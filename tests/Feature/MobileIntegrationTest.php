<?php

namespace Tests\Feature;

use App\Models\AcceptedRequestItem;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Category;
use App\Models\DailySettlement;
use App\Models\DsrProfile;
use App\Models\Item;
use App\Models\RepStock;
use App\Models\Route;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $rep = User::factory()->create(['role' => 'DSR_REP']);
        $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);
        $route = Route::create(['branch_id' => $branch->id, 'route_name' => 'Route', 'route_code' => 'RT']);
        DsrProfile::create(['user_id' => $rep->id, 'rep_code' => 'R1', 'branch_id' => $branch->id,
            'assigned_route_id' => $route->id, 'dsr_status' => 'ACTIVE']);
        $shop = Shop::create(['shop_code' => 'SHOP', 'shop_name' => 'Outlet', 'route_id' => $route->id, 'created_by' => $rep->id]);
        $category = Category::create(['name' => 'Category', 'code' => 'CAT']);
        $item = Item::create(['name' => 'Item', 'code' => 'ITEM', 'category_id' => $category->id,
            'purchase_price' => 50, 'selling_price' => 100, 'status' => 'ACTIVE']);
        $stock = RepStock::create(['rep_id' => $rep->id, 'status' => 'APPROVED', 'total_value' => 1000]);
        $line = AcceptedRequestItem::create(['rep_stock_id' => $stock->id, 'item_id' => $item->id, 'quantity' => 10]);
        Sanctum::actingAs($rep);

        return compact('rep', 'branch', 'route', 'shop', 'item', 'stock', 'line');
    }

    private function payload(array $data, array $overrides = []): array
    {
        return array_replace(['shop_id' => $data['shop']->id, 'payment_type' => 'CASH',
            'items' => [['item_id' => $data['item']->id, 'quantity' => 2]]], $overrides);
    }

    public function test_both_sales_urls_consume_stock_and_use_authenticated_rep_branch(): void
    {
        $data = $this->fixture();
        $other = User::factory()->create();
        foreach (['/api/v1/sales', '/api/v1/mobile/sales'] as $url) {
            $this->postJson($url, $this->payload($data, ['rep_id' => $other->id, 'user_id' => $other->id]))
                ->assertCreated()->assertJsonPath('data.rep_id', $data['rep']->id)
                ->assertJsonPath('data.branch_id', $data['branch']->id);
        }
        $this->assertEquals(6, $data['line']->fresh()->quantity);
        $this->assertEquals(600, $data['stock']->fresh()->total_value);
    }

    public function test_overselling_and_duplicate_item_lines_roll_back_the_whole_sale(): void
    {
        $data = $this->fixture();
        $this->postJson('/api/v1/mobile/sales', $this->payload($data, ['items' => [
            ['item_id' => $data['item']->id, 'quantity' => 7],
            ['item_id' => $data['item']->id, 'quantity' => 7],
        ]]))->assertUnprocessable();
        $this->assertEquals(10, $data['line']->fresh()->quantity);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_payments', 0);
    }

    public function test_inconsistent_split_payments_and_overpayments_are_rejected(): void
    {
        $data = $this->fixture();
        $this->postJson('/api/v1/mobile/sales', $this->payload($data, ['paid_amount' => 200,
            'payment_type' => 'SPLIT', 'payments' => [['payment_type' => 'CASH', 'amount' => 50]]]))->assertUnprocessable();
        $this->postJson('/api/v1/mobile/sales', $this->payload($data, ['paid_amount' => 201]))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertEquals(10, $data['line']->fresh()->quantity);
    }

    public function test_sales_lists_scope_rep_and_accept_date_range_aliases(): void
    {
        $data = $this->fixture();
        $other = User::factory()->create();
        $mine = Sale::create(['sale_code' => 'MINE', 'rep_id' => $data['rep']->id, 'sale_date' => '2026-10-20 12:00:00']);
        Sale::create(['sale_code' => 'OLD', 'rep_id' => $data['rep']->id, 'sale_date' => '2026-10-19 12:00:00']);
        $foreign = Sale::create(['sale_code' => 'OTHER', 'rep_id' => $other->id, 'sale_date' => '2026-10-20 12:00:00']);
        foreach (['/api/v1/sales', '/api/v1/mobile/sales'] as $url) {
            $this->getJson($url.'?start_date=2026-10-20&end_date=2026-10-20&rep_id='.$other->id)
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
            $this->getJson($url.'/'.$foreign->id)->assertNotFound();
        }
    }

    public function test_shop_edit_is_supported_and_foreign_shops_are_protected(): void
    {
        $data = $this->fixture();
        $this->putJson('/api/v1/mobile/shops/'.$data['shop']->id, ['shop_name' => 'Updated', 'latitude' => 6.9, 'longitude' => 79.8])
            ->assertOk()->assertJsonPath('data.shop_name', 'Updated');
        $this->assertDatabaseHas('shop_gps_locations', ['shop_id' => $data['shop']->id, 'captured_by_user_id' => $data['rep']->id]);
        $foreignRoute = Route::create(['route_name' => 'Other', 'route_code' => 'OTHER', 'branch_id' => $data['branch']->id]);
        $foreign = Shop::create(['shop_name' => 'Foreign', 'shop_code' => 'FOREIGN', 'route_id' => $foreignRoute->id]);
        $this->putJson('/api/v1/mobile/shops/'.$foreign->id, ['shop_name' => 'Stolen'])->assertNotFound();
        $this->deleteJson('/api/shops/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/v1/mobile/shops')->assertJsonCount(1, 'data');
        $this->putJson('/api/v1/mobile/shops/'.$data['shop']->id, ['latitude' => 100])->assertUnprocessable();
    }

    public function test_credit_collection_allocates_oldest_invoices_and_updates_daily_collections(): void
    {
        $data = $this->fixture();
        $old = Sale::create(['sale_code' => 'CREDIT1', 'shop_id' => $data['shop']->id, 'rep_id' => $data['rep']->id,
            'sale_date' => now()->subDays(2), 'total_amount' => 100, 'due_amount' => 100, 'payment_type' => 'CREDIT']);
        $recent = Sale::create(['sale_code' => 'CREDIT2', 'shop_id' => $data['shop']->id, 'rep_id' => $data['rep']->id,
            'sale_date' => now()->subDay(), 'total_amount' => 100, 'due_amount' => 100, 'payment_type' => 'CREDIT']);
        $data['shop']->update(['current_credit_balance' => 200]);
        $this->postJson('/api/v1/mobile/shops/'.$data['shop']->id.'/settle-credit', ['amount' => 125])->assertOk();
        $this->assertEquals(0, $old->fresh()->due_amount);
        $this->assertEquals(75, $recent->fresh()->due_amount);
        $this->assertEquals(75, $data['shop']->fresh()->current_credit_balance);
        $this->assertDatabaseCount('sale_payments', 2);
        $this->assertEquals($data['rep']->id, SalePayment::first()->collected_by);
        $this->getJson('/api/v1/mobile/daily-settlement')->assertOk()->assertJsonPath('data.total_cash_collected', 125);
        $this->postJson('/api/v1/mobile/shops/'.$data['shop']->id.'/settle-credit', ['amount' => -1])->assertUnprocessable();
        $this->postJson('/api/v1/mobile/shops/'.$data['shop']->id.'/settle-credit', ['amount' => 76])->assertUnprocessable();
    }

    public function test_rep_cannot_collect_another_reps_credit_or_approve_settlements(): void
    {
        $data = $this->fixture();
        $other = User::factory()->create();
        Sale::create(['sale_code' => 'FOREIGN', 'shop_id' => $data['shop']->id, 'rep_id' => $other->id, 'total_amount' => 100, 'due_amount' => 100]);
        $data['shop']->update(['current_credit_balance' => 100]);
        $this->postJson('/api/v1/mobile/shops/'.$data['shop']->id.'/settle-credit', ['amount' => 50])->assertUnprocessable();
        $this->getJson('/api/v1/mobile/shops/'.$data['shop']->id.'/credit-sales')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/daily-settlements')->assertForbidden();
        $this->postJson('/api/v1/daily-settlements/1/status', ['status' => 'APPROVED'])->assertForbidden();
        $this->getJson('/api/v1/rep-stocks/rep/'.$other->id)->assertNotFound();
        $this->postJson('/api/v1/rep-stocks/issue-stock', [])->assertForbidden();
        $this->postJson('/api/reps', [])->assertForbidden();
        $this->postJson('/api/bank-deposits', [])->assertForbidden();
    }

    public function test_retrying_settlement_deposits_does_not_duplicate_bank_balance(): void
    {
        $data = $this->fixture();
        $bank = BankAccount::create(['bank_name' => 'Bank', 'account_name' => 'Main', 'account_number' => '123', 'current_balance' => 10]);
        $payload = ['date' => now()->format('Y-m-d'), 'bank_deposits' => [['bank_account_id' => $bank->id, 'amount' => 50]]];
        $this->postJson('/api/v1/mobile/daily-settlement', $payload)->assertCreated();
        $this->postJson('/api/v1/mobile/daily-settlement', $payload)->assertCreated();
        $this->assertDatabaseCount('daily_settlements', 1);
        $this->assertDatabaseCount('dsr_bank_deposits', 1);
        $this->assertEquals(60, $bank->fresh()->current_balance);
        $payload['bank_deposits'][0]['amount'] = 75;
        $this->postJson('/api/v1/mobile/daily-settlement', $payload)->assertCreated();
        $this->assertEquals(85, $bank->fresh()->current_balance);
        $this->postJson('/api/v1/mobile/daily-settlement', ['date' => $payload['date'], 'bank_deposits' => []])->assertCreated();
        $this->assertEquals(10, $bank->fresh()->current_balance);
        DailySettlement::first()->update(['status' => 'APPROVED']);
        $this->postJson('/api/v1/mobile/daily-settlement', $payload)->assertUnprocessable();
        $this->assertEquals(10, $bank->fresh()->current_balance);
    }

    public function test_inactive_rep_token_is_rejected_after_deactivation(): void
    {
        $data = $this->fixture();
        $data['rep']->update(['status' => 'INACTIVE']);
        $this->getJson('/api/v1/mobile/rep/check-token')->assertForbidden();
        $this->getJson('/api/v1/sales')->assertForbidden();
    }

    public function test_agent_cancellation_restores_allocated_stock_only_once(): void
    {
        $data = $this->fixture();
        $response = $this->postJson('/api/v1/mobile/sales', $this->payload($data, ['payment_type' => 'CREDIT']))->assertCreated();
        $id = $response->json('data.id');
        $this->assertEquals(8, $data['line']->fresh()->quantity);
        $this->assertEquals(200, $data['shop']->fresh()->current_credit_balance);
        $agent = User::factory()->create(['role' => 'AGENT']);
        Sanctum::actingAs($agent);
        $this->putJson('/api/sales/'.$id, ['status' => 'CANCELLED'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->putJson('/api/sales/'.$id, ['status' => 'CANCELLED'])->assertOk();
        $this->assertEquals(10, $data['line']->fresh()->quantity);
        $this->assertEquals(0, $data['shop']->fresh()->current_credit_balance);
        $this->assertEquals('CANCELLED', Sale::find($id)->status);
        $this->putJson('/api/sales/'.$id, ['status' => 'COMPLETED'])->assertUnprocessable();
    }
}
