<?php

namespace Tests\Feature;

use App\Models\AcceptedRequestItem;
use App\Models\BankAccount;
use App\Models\BankFundTransfer;
use App\Models\Branch;
use App\Models\Category;
use App\Models\DsrBankDeposit;
use App\Models\DsrProfile;
use App\Models\GoodsReceivedNote;
use App\Models\Item;
use App\Models\RepStock;
use App\Models\Route;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $agent = User::factory()->create(['role' => 'AGENT']);
        $rep = User::factory()->create(['role' => 'DSR_REP']);
        $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
        $route = Route::create(['branch_id' => $branch->id, 'route_name' => 'Main route', 'route_code' => 'RT']);
        DsrProfile::create(['user_id' => $rep->id, 'rep_code' => 'REP1', 'branch_id' => $branch->id,
            'assigned_route_id' => $route->id, 'dsr_status' => 'ACTIVE']);
        $shop = Shop::create(['route_id' => $route->id, 'shop_code' => 'S1', 'shop_name' => 'Shop', 'created_by' => $rep->id]);
        $category = Category::create(['name' => 'Category', 'code' => 'CAT']);
        $item = Item::create(['name' => 'Item', 'code' => 'ITEM', 'category_id' => $category->id,
            'purchase_price' => 50, 'selling_price' => 100, 'status' => 'ACTIVE']);
        Sanctum::actingAs($agent);
        return compact('agent', 'rep', 'branch', 'route', 'shop', 'item');
    }

    private function account(string $number, float $balance = 0): BankAccount
    {
        return BankAccount::create(['bank_name' => 'Bank', 'account_name' => 'Agency', 'account_number' => $number,
            'currency' => 'LKR', 'is_active' => true, 'current_balance' => $balance]);
    }

    public function test_fund_transfer_conserves_money_and_is_idempotent(): void
    {
        $this->fixture();
        $from = $this->account('1', 500);
        $to = $this->account('2', 100);
        $payload = ['request_key' => (string) Str::uuid(), 'from_bank_account_id' => $from->id,
            'to_bank_account_id' => $to->id, 'amount' => 75];
        $this->postJson('/api/v1/fund-transfers', $payload)->assertCreated();
        $this->postJson('/api/v1/fund-transfers', $payload)->assertCreated();
        $this->assertEquals(425, $from->fresh()->current_balance);
        $this->assertEquals(175, $to->fresh()->current_balance);
        $this->assertDatabaseCount('bank_fund_transfers', 1);
        $this->deleteJson('/api/v1/bank-accounts/'.$to->id)->assertUnprocessable();
        $this->getJson('/api/v1/fund-transfers')->assertOk()->assertJsonCount(1, 'data');
        $payload['amount'] = 80;
        $this->postJson('/api/v1/fund-transfers', $payload)->assertUnprocessable();
        $payload['request_key'] = (string) Str::uuid();
        $payload['amount'] = 426;
        $this->postJson('/api/v1/fund-transfers', $payload)->assertUnprocessable();
        $this->assertEquals(425, $from->fresh()->current_balance);
    }

    public function test_deposit_verification_does_not_credit_twice_and_rejection_reverses_once(): void
    {
        $data = $this->fixture();
        $account = $this->account('1', 120);
        $deposit = DsrBankDeposit::create(['bank_account_id' => $account->id, 'rep_id' => $data['rep']->id,
            'deposit_date' => now(), 'amount' => 20, 'status' => 'CONFIRMED']);
        $this->getJson('/api/v1/bank-deposits')->assertOk()->assertJsonCount(1, 'data');
        $url = '/api/v1/bank-deposits/'.$deposit->id.'/status';
        $this->postJson($url, ['status' => 'VERIFIED'])->assertOk();
        $this->postJson($url, ['status' => 'VERIFIED'])->assertOk();
        $this->assertEquals(120, $account->fresh()->current_balance);
        $this->postJson($url, ['status' => 'REJECTED'])->assertOk();
        $this->postJson($url, ['status' => 'REJECTED'])->assertOk();
        $this->assertEquals(100, $account->fresh()->current_balance);
        $this->assertEquals($data['agent']->id, $deposit->fresh()->verified_by);
    }

    public function test_rejected_sale_bank_payment_restores_invoice_debt_once(): void
    {
        $data = $this->fixture();
        $account = $this->account('1', 100);
        $sale = Sale::create(['sale_code' => 'PAID', 'shop_id' => $data['shop']->id, 'rep_id' => $data['rep']->id,
            'total_amount' => 100, 'paid_amount' => 100, 'due_amount' => 0]);
        $payment = SalePayment::create(['sale_id' => $sale->id, 'payment_type' => 'ONLINE', 'bank_account_id' => $account->id,
            'amount' => 100, 'status' => 'PENDING']);
        $url = '/api/v1/bank-transfers/'.$payment->id.'/verify';
        $this->postJson($url, ['status' => 'REJECTED'])->assertOk();
        $this->postJson($url, ['status' => 'REJECTED'])->assertOk();
        $this->assertEquals(0, $account->fresh()->current_balance);
        $this->assertEquals(100, $sale->fresh()->due_amount);
        $this->assertEquals(100, $data['shop']->fresh()->current_credit_balance);
        $this->postJson($url, ['status' => 'VERIFIED'])->assertOk();
        $this->assertEquals(100, $account->fresh()->current_balance);
        $this->assertEquals(0, $sale->fresh()->due_amount);
        $this->assertEquals(0, $data['shop']->fresh()->current_credit_balance);
    }

    public function test_agent_cannot_replace_shop_debt_through_metadata_editing(): void
    {
        $data = $this->fixture();
        $data['shop']->update(['current_credit_balance' => 50]);
        $this->putJson('/api/shops/'.$data['shop']->id, ['current_credit_balance' => 0])->assertUnprocessable();
        $this->assertEquals(50, $data['shop']->fresh()->current_credit_balance);
    }

    public function test_payroll_lifecycle_and_server_calculated_amounts_persist(): void
    {
        $data = $this->fixture();
        $payload = ['referrer_id' => $data['rep']->id, 'pay_period' => '2026-10', 'basic_salary' => 1000,
            'bike_allowance' => 100, 'fuel_allowance' => 200, 'earned_commission' => 300, 'fuel_advances' => 50,
            'epf_etf_deduction' => 25, 'net_payable' => 999999];
        $response = $this->postJson('/api/payroll', $payload)->assertCreated()->assertJsonPath('data.net_payable', 1525);
        $id = $response->json('data.id');
        $this->postJson('/api/payroll', $payload)->assertUnprocessable();
        $this->putJson('/api/payroll/'.$id, ['payment_status' => 'DISBURSED'])->assertUnprocessable();
        $this->putJson('/api/payroll/'.$id, ['payment_status' => 'APPROVED'])->assertOk();
        $this->putJson('/api/payroll/'.$id, ['payment_status' => 'DISBURSED'])->assertOk();
        $this->getJson('/api/payroll/'.$id)->assertOk()->assertJsonPath('data.payment_status', 'DISBURSED');
        $this->deleteJson('/api/payroll/'.$id)->assertUnprocessable();
        $this->putJson('/api/payroll/'.$id, ['basic_salary' => 1])->assertUnprocessable();
    }

    public function test_grn_edit_delete_and_issued_stock_guards_are_transactional(): void
    {
        $data = $this->fixture();
        $payload = ['branch_id' => $data['branch']->id, 'supplier_invoice_no' => 'INV-1',
            'items' => [['item_id' => $data['item']->id, 'quantity_received' => 10, 'unit_price' => 50, 'batch_number' => 'B1']]];
        $id = $this->postJson('/api/grn', $payload)->assertCreated()->json('data.id');
        $this->assertEquals(10, Stock::first()->quantity);
        $this->putJson('/api/grn/'.$id, ['notes' => 'Saved note'])->assertOk()->assertJsonPath('data.total_value', 500);
        $this->assertEquals(10, Stock::first()->quantity);
        $payload['items'][0]['quantity_received'] = 12;
        $this->putJson('/api/grn/'.$id, $payload)->assertOk();
        $this->assertEquals(12, Stock::first()->quantity);
        Stock::first()->update(['quantity' => 3]);
        $this->deleteJson('/api/grn/'.$id)->assertUnprocessable();
        $this->assertDatabaseCount('goods_received_notes', 1);
        Stock::first()->update(['quantity' => 12]);
        $this->deleteJson('/api/grn/'.$id)->assertOk();
        $this->assertEquals(0, Stock::first()->quantity);
    }

    public function test_rep_damage_adjustment_and_return_use_the_mobile_stock_store(): void
    {
        $data = $this->fixture();
        $header = RepStock::create(['rep_id' => $data['rep']->id, 'status' => 'APPROVED', 'total_value' => 1000]);
        $line = AcceptedRequestItem::create(['rep_stock_id' => $header->id, 'item_id' => $data['item']->id,
            'batch_number' => 'B1', 'quantity' => 10]);
        $payload = ['target_type' => 'DSR_REP', 'branch_id' => $data['branch']->id, 'referrer_id' => $data['rep']->id,
            'item_id' => $data['item']->id, 'adjustment_type' => 'DEDUCT_DAMAGE', 'quantity_delta' => -3, 'reason' => 'Damaged'];
        $this->postJson('/api/stock-adjustments', $payload)->assertCreated();
        $this->assertEquals(7, $line->fresh()->quantity);
        $this->assertEquals(700, $header->fresh()->total_value);
        $payload['quantity_delta'] = -8;
        $this->postJson('/api/stock-adjustments', $payload)->assertUnprocessable();
        $payload['quantity_delta'] = -2;
        $payload['adjustment_type'] = 'RETURN_TO_WAREHOUSE';
        $this->postJson('/api/stock-adjustments', $payload)->assertCreated();
        $this->assertEquals(5, $line->fresh()->quantity);
        $this->assertDatabaseHas('stocks', ['batch_number' => 'B1', 'quantity' => 2, 'referrer_id' => null]);
        $this->assertDatabaseCount('rep_stock_adjustment_lines', 2);
        Sanctum::actingAs($data['rep']);
        $this->postJson('/api/v1/mobile/sales', ['shop_id' => $data['shop']->id,
            'items' => [['item_id' => $data['item']->id, 'quantity' => 6]]])->assertUnprocessable();
    }

    public function test_web_login_requires_exact_password_and_an_active_agent(): void
    {
        $agent = User::factory()->create(['role' => 'AGENT', 'password' => Hash::make('password')]);
        $this->postJson('/api/login', ['phone' => $agent->phone, 'password' => 'password123'])->assertUnauthorized();
        $this->postJson('/api/login', ['phone' => $agent->phone, 'password' => 'password'])->assertOk();
        $agent->update(['status' => 'INACTIVE']);
        $this->postJson('/api/login', ['phone' => $agent->phone, 'password' => 'password'])->assertForbidden();
    }

    public function test_web_logout_revokes_the_actual_bearer_token(): void
    {
        $agent = User::factory()->create(['role' => 'AGENT']);
        $token = $agent->createToken('web')->plainTextToken;
        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_reps_cannot_call_financial_management_routes(): void
    {
        $data = $this->fixture();
        Sanctum::actingAs($data['rep']);
        $this->getJson('/api/v1/bank-deposits')->assertForbidden();
        $this->postJson('/api/v1/fund-transfers', [])->assertForbidden();
        $this->postJson('/api/payroll', [])->assertForbidden();
        $this->postJson('/api/grn', [])->assertForbidden();
    }

    public function test_warehouse_issuance_rejects_overselling_and_retries_keep_one_transfer(): void
    {
        $data = $this->fixture();
        $stock = Stock::create(['branch_id' => $data['branch']->id, 'item_id' => $data['item']->id, 'batch_number' => 'B1',
            'quantity' => 5, 'unit_price' => 100, 'unit_cost' => 50, 'total_value' => 500]);
        $payload = ['request_key' => (string) Str::uuid(), 'from_branch_id' => $data['branch']->id,
            'to_referrer_id' => $data['rep']->id, 'items' => [['item_id' => $data['item']->id, 'quantity' => 6]]];
        $this->postJson('/api/v1/rep-stocks/issue-stock', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('rep_stocks', 0);
        $this->assertDatabaseCount('stock_requests', 0);
        $payload['items'][0]['quantity'] = 4;
        $this->postJson('/api/v1/rep-stocks/issue-stock', $payload)->assertOk();
        $this->postJson('/api/v1/rep-stocks/issue-stock', $payload)->assertOk();
        $this->assertEquals(1, $stock->fresh()->quantity);
        $this->assertDatabaseCount('rep_stocks', 1);
        $this->assertDatabaseCount('stock_transfers', 1);
        $transfer = \App\Models\StockTransfer::first();
        $this->putJson('/api/stock-transfers/'.$transfer->id, ['total_value' => 1])->assertUnprocessable();
        $this->deleteJson('/api/stock-transfers/'.$transfer->id)->assertUnprocessable();
        $this->assertDatabaseHas('stock_request_items', ['stock_request_id' => $transfer->request_id, 'approved_quantity' => 4]);

        $this->assertDatabaseHas('accepted_request_items', ['item_id' => $data['item']->id, 'quantity' => 4, 'batch_number' => 'B1']);
    }

    public function test_settlement_totals_are_calculated_server_side_and_agent_can_select_rep(): void
    {
        $data = $this->fixture();
        Sale::create(['sale_code' => 'LIVE', 'rep_id' => $data['rep']->id, 'shop_id' => $data['shop']->id,
            'sale_date' => now(), 'status' => 'COMPLETED', 'payment_type' => 'CASH', 'total_amount' => 100, 'paid_amount' => 100]);
        $payload = ['rep_id' => $data['rep']->id, 'date' => now()->toDateString(), 'total_sales_value' => 999999, 'cash_counted' => 90];
        $this->postJson('/api/v1/daily-settlement', $payload)->assertCreated()->assertJsonPath('data.total_sales_value', '100.00');
        $this->getJson('/api/v1/daily-settlement?rep_id='.$data['rep']->id.'&date='.$payload['date'])
            ->assertOk()->assertJsonPath('data.total_sales_value', 100)->assertJsonPath('data.cash_counted', 90);
    }

    public function test_commission_configuration_is_persisted_and_validated(): void
    {
        $data = $this->fixture();
        $payload = ['itemRules' => [['itemId' => $data['item']->id, 'type' => 'PERCENTAGE', 'rateValue' => 2.5]], 'targetBonusAmount' => 500];
        $this->putJson('/api/payroll-settings', $payload)->assertOk();
        $this->getJson('/api/payroll-settings')->assertOk()->assertJsonPath('data.itemRules.0.rateValue', 2.5);
        $payload['itemRules'][0]['rateValue'] = 101;
        $this->putJson('/api/payroll-settings', $payload)->assertUnprocessable();
    }

    public function test_direct_deposit_retries_are_idempotent_and_balance_editing_is_blocked(): void
    {
        $this->fixture();
        $account = $this->account('1', 100);
        $payload = ['request_key' => (string) Str::uuid(), 'bank_account_id' => $account->id, 'amount' => 50];
        $this->postJson('/api/v1/bank-deposits', $payload)->assertCreated();
        $this->postJson('/api/v1/bank-deposits', $payload)->assertCreated();
        $this->assertEquals(150, $account->fresh()->current_balance);
        $this->assertDatabaseCount('dsr_bank_deposits', 1);
        $this->putJson('/api/v1/bank-accounts/'.$account->id, ['current_balance' => 999])->assertUnprocessable();
    }
    public function test_receipt_attachment_cannot_create_reclassify_or_recredit_payments(): void
    {
        $data = $this->fixture();
        $account = $this->account('1', 0);
        $sale = Sale::create(['sale_code' => 'RECEIPT', 'rep_id' => $data['rep']->id, 'shop_id' => $data['shop']->id,
            'status' => 'COMPLETED', 'total_amount' => 100, 'paid_amount' => 0, 'due_amount' => 100]);
        $this->postJson('/api/v1/mobile/sales/'.$sale->id.'/bank-slip', ['slip_image' => 'bank_slips/a.jpg'])->assertUnprocessable();
        $this->assertDatabaseCount('sale_payments', 0);
        $payment = SalePayment::create(['sale_id' => $sale->id, 'payment_type' => 'ONLINE', 'bank_account_id' => $account->id,
            'amount' => 100, 'status' => 'REJECTED']);
        $url = '/api/v1/mobile/payments/'.$payment->id.'/bank-slip';
        $this->postJson($url, ['slip_image' => 'bank_slips/a.jpg'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->assertEquals(0, $account->fresh()->current_balance);
        $this->postJson($url, ['slip_image' => 'bank_slips/a.jpg', 'amount' => 200])->assertUnprocessable();
        $payment->update(['payment_type' => 'CASH']);
        $this->postJson($url, ['slip_image' => 'bank_slips/a.jpg'])->assertUnprocessable();
        $this->assertEquals('CASH', $payment->fresh()->payment_type);
    }

    public function test_targets_validate_rep_period_and_exclude_cancelled_sales(): void
    {
        $data = $this->fixture();
        $this->postJson('/api/targets', [])->assertUnprocessable();
        $payload = ['referrer_id'=>$data['rep']->id,'target_month'=>'2026-10','target_amount'=>1000];
        $this->postJson('/api/targets', $payload)->assertCreated();
        Sale::create(['sale_code'=>'TARGET-CANCELLED','rep_id'=>$data['rep']->id,'sale_date'=>'2026-10-21','status'=>'CANCELLED','total_amount'=>100]);
        $this->getJson('/api/targets')->assertOk()->assertJsonPath('data.0.achieved_amount', 0);
        $payload['target_month']='October 2026';
        $this->postJson('/api/targets', $payload)->assertUnprocessable();
    }

}
