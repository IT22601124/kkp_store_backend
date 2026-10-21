<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Route;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\User;
use Database\Seeders\BankAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BankTransferSlipTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\IssuedRepStock;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    protected function createTestData(): array
    {
        $this->seed(BankAccountSeeder::class);

        $branch = Branch::create(['name' => 'Colombo Branch', 'code' => 'BR-COL']);
        $route = Route::create([
            'branch_id' => $branch->id,
            'route_name' => 'Colombo Central',
            'route_code' => 'RT-COL-01',
        ]);
        $shop = Shop::create([
            'route_id' => $route->id,
            'shop_code' => 'SHP-COL-001',
            'shop_name' => 'Metro Super City',
            'owner_name' => 'Kamal Silva',
            'phone' => '0771234567',
            'address' => '123 Galle Road, Colombo',
        ]);

        $category = Category::create(['name' => 'Beverages', 'code' => 'BEV']);
        $item = Item::create([
            'name' => 'Fruit Juice 1L',
            'code' => 'FJ-1L',
            'category_id' => $category->id,
            'purchase_price' => 200.00,
            'selling_price' => 350.00,
            'status' => 'ACTIVE',
        ]);

        $bankAccount = BankAccount::where('bank_name', 'Commercial Bank of Ceylon')->firstOrFail();

        $rep = User::factory()->create(['role' => 'DSR_REP']);
        $agent = User::factory()->create(['role' => 'AGENT']);

        $this->seedIssuedStock($rep, $item, $branch, $route);

        return compact('branch', 'shop', 'category', 'item', 'bankAccount', 'rep', 'agent');
    }

    public function test_mobile_rep_can_create_sale_with_bank_transfer_and_upload_slip_file(): void
    {
        $data = $this->createTestData();
        Sanctum::actingAs($data['rep']);

        $slipFile = UploadedFile::fake()->image('bank_slip_receipt.jpg');

        $response = $this->post('/api/v1/mobile/sales', [
            'shop_id'         => $data['shop']->id,
            'payment_type'    => 'BANK_TRANSFER',
            'bank_account_id' => $data['bankAccount']->id,
            'reference_number'=> 'SLIP-REF-998877',
            'bank_slip'       => $slipFile,
            'items'           => [
                [
                    'item_id'    => $data['item']->id,
                    'quantity'   => 2,
                    'unit_price' => 350.00,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_type', 'BANK_TRANSFER')
            ->assertJsonPath('data.payments.0.payment_type', 'BANK_TRANSFER')
            ->assertJsonPath('data.payments.0.status', 'PENDING')
            ->assertJsonPath('data.payments.0.bank_account_id', $data['bankAccount']->id)
            ->assertJsonPath('data.payments.0.reference_number', 'SLIP-REF-998877');

        $payment = SalePayment::first();
        $this->assertNotNull($payment->slip_image);
        $this->assertNotNull($payment->slip_url);
        $this->assertEquals('PENDING', $payment->status);

        Storage::disk('public')->assertExists($payment->slip_image);
    }

    public function test_rep_can_create_sale_with_split_payments_and_bank_slip(): void
    {
        $data = $this->createTestData();
        Sanctum::actingAs($data['rep']);

        $slipFile = UploadedFile::fake()->image('slip_split.png');

        $response = $this->post('/api/v1/mobile/sales', [
            'shop_id'      => $data['shop']->id,
            'payment_type' => 'SPLIT',
            'bank_slip'    => $slipFile,
            'items'        => [
                [
                    'item_id'    => $data['item']->id,
                    'quantity'   => 4,
                    'unit_price' => 350.00,
                ],
            ],
            'payments'     => [
                [
                    'payment_type' => 'CASH',
                    'amount'       => 400.00,
                ],
                [
                    'payment_type'     => 'BANK_TRANSFER',
                    'amount'           => 1000.00,
                    'bank_account_id'  => $data['bankAccount']->id,
                    'reference_number' => 'SPLIT-TXN-001',
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $bankPayment = SalePayment::where('payment_type', 'BANK_TRANSFER')->firstOrFail();
        $this->assertEquals(1000.00, (float) $bankPayment->amount);
        $this->assertEquals('PENDING', $bankPayment->status);
        $this->assertNotNull($bankPayment->slip_image);
        $this->assertNotNull($bankPayment->slip_url);

        Storage::disk('public')->assertExists($bankPayment->slip_image);
    }

    public function test_rep_can_upload_standalone_slip_and_attach_to_sale(): void
    {
        $data = $this->createTestData();
        Sanctum::actingAs($data['rep']);

        // 1. Upload standalone slip
        $slipFile = UploadedFile::fake()->image('test_deposit.jpg');
        $uploadResp = $this->post('/api/v1/mobile/upload-bank-slip', [
            'bank_slip' => $slipFile,
        ]);

        $uploadResp->assertStatus(201)
            ->assertJsonPath('success', true);

        $slipPath = $uploadResp->json('data.slip_path');
        $this->assertNotEmpty($slipPath);
        Storage::disk('public')->assertExists($slipPath);

        // 2. Create a sale without slip initially
        $sale = Sale::create([
            'sale_code'    => 'SALE-20261018-0001',
            'rep_id'       => $data['rep']->id,
            'shop_id'      => $data['shop']->id,
            'branch_id'    => $data['branch']->id,
            'payment_type' => 'BANK_TRANSFER',
            'status'       => 'COMPLETED',
            'total_amount' => 700.00,
            'paid_amount'  => 700.00,
            'due_amount'   => 0.00,
        ]);

        $payment = SalePayment::create([
            'sale_id'          => $sale->id,
            'bank_account_id'  => $data['bankAccount']->id,
            'payment_type'     => 'BANK_TRANSFER',
            'amount'           => 700.00,
            'reference_number' => 'REF-BEFORE-SLIP',
            'payment_date'     => now(),
        ]);

        // 3. Attach slip to the sale
        $attachResp = $this->postJson("/api/v1/mobile/sales/{$sale->id}/bank-slip", [
            'slip_image'       => $slipPath,
            'reference_number' => 'REF-AFTER-SLIP',
        ]);

        $attachResp->assertStatus(200)
            ->assertJsonPath('success', true);

        $payment->refresh();
        $this->assertEquals($slipPath, $payment->slip_image);
        $this->assertEquals('REF-AFTER-SLIP', $payment->reference_number);
        $this->assertEquals('PENDING', $payment->status);
    }

    public function test_rep_can_attach_slip_directly_to_payment(): void
    {
        $data = $this->createTestData();
        Sanctum::actingAs($data['rep']);

        $sale = Sale::create([
            'sale_code'    => 'SALE-20261018-0002',
            'rep_id'       => $data['rep']->id,
            'shop_id'      => $data['shop']->id,
            'branch_id'    => $data['branch']->id,
            'payment_type' => 'BANK_TRANSFER',
            'total_amount' => 500.00,
            'paid_amount'  => 500.00,
        ]);

        $payment = SalePayment::create([
            'sale_id'         => $sale->id,
            'bank_account_id' => $data['bankAccount']->id,
            'payment_type'    => 'ONLINE',
            'amount'          => 500.00,
        ]);

        $slipFile = UploadedFile::fake()->image('payment_receipt.png');
        $response = $this->post("/api/v1/mobile/payments/{$payment->id}/bank-slip", [
            'bank_slip'        => $slipFile,
            'reference_number' => 'TXN-DIRECT-001',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.reference_number', 'TXN-DIRECT-001');

        $payment->refresh();
        $this->assertNotNull($payment->slip_image);
        Storage::disk('public')->assertExists($payment->slip_image);
    }

    public function test_agent_can_view_bank_transfers_and_slips_on_web(): void
    {
        $data = $this->createTestData();

        // Create 2 sales with bank payments
        $sale1 = Sale::create([
            'sale_code'    => 'SALE-20261018-0010',
            'rep_id'       => $data['rep']->id,
            'shop_id'      => $data['shop']->id,
            'branch_id'    => $data['branch']->id,
            'payment_type' => 'BANK_TRANSFER',
            'total_amount' => 1200.00,
            'paid_amount'  => 1200.00,
        ]);

        $payment1 = SalePayment::create([
            'sale_id'          => $sale1->id,
            'bank_account_id'  => $data['bankAccount']->id,
            'payment_type'     => 'BANK_TRANSFER',
            'amount'           => 1200.00,
            'reference_number' => 'TXN-WEB-100',
            'slip_image'       => 'bank_slips/sample1.jpg',
            'status'           => 'PENDING',
        ]);

        $sale2 = Sale::create([
            'sale_code'    => 'SALE-20261018-0011',
            'rep_id'       => $data['rep']->id,
            'shop_id'      => $data['shop']->id,
            'branch_id'    => $data['branch']->id,
            'payment_type' => 'ONLINE',
            'total_amount' => 800.00,
            'paid_amount'  => 800.00,
        ]);

        $payment2 = SalePayment::create([
            'sale_id'          => $sale2->id,
            'bank_account_id'  => $data['bankAccount']->id,
            'payment_type'     => 'ONLINE',
            'amount'           => 800.00,
            'reference_number' => 'TXN-WEB-101',
            'status'           => 'VERIFIED',
        ]);

        // Agent logs into web dashboard
        Sanctum::actingAs($data['agent']);

        $response = $this->getJson('/api/v1/bank-transfers');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.total_transfers', 2)
            ->assertJsonPath('summary.pending_verification', 1)
            ->assertJsonPath('summary.verified_transfers', 1)
            ->assertJsonPath('summary.with_slip_uploaded', 1);

        $items = $response->json('data');
        $this->assertCount(2, $items);

        $slipItem = collect($items)->firstWhere('reference_number', 'TXN-WEB-100');
        $this->assertNotNull($slipItem);
        $this->assertEquals('Commercial Bank of Ceylon', $slipItem['bank_account']['bank_name']);
        $this->assertEquals('Metro Super City', $slipItem['sale']['shop']['name']);
        $this->assertNotNull($slipItem['slip_url']);

        $noSlipItem = collect($items)->firstWhere('reference_number', 'TXN-WEB-101');
        $this->assertNotNull($noSlipItem);
        $this->assertNull($noSlipItem['slip_url']);
    }

    public function test_agent_can_verify_bank_transfer_slip_on_web(): void
    {
        $data = $this->createTestData();

        $sale = Sale::create([
            'sale_code'    => 'SALE-20261018-0020',
            'rep_id'       => $data['rep']->id,
            'shop_id'      => $data['shop']->id,
            'branch_id'    => $data['branch']->id,
            'payment_type' => 'BANK_TRANSFER',
            'total_amount' => 1500.00,
            'paid_amount'  => 1500.00,
        ]);

        $payment = SalePayment::create([
            'sale_id'          => $sale->id,
            'bank_account_id'  => $data['bankAccount']->id,
            'payment_type'     => 'BANK_TRANSFER',
            'amount'           => 1500.00,
            'reference_number' => 'REF-TO-VERIFY',
            'slip_image'       => 'bank_slips/receipt.jpg',
            'status'           => 'PENDING',
        ]);

        Sanctum::actingAs($data['agent']);

        $response = $this->postJson("/api/v1/bank-transfers/{$payment->id}/verify", [
            'status' => 'VERIFIED',
            'notes'  => 'Slip checked with Commercial Bank statement',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'VERIFIED')
            ->assertJsonPath('data.verified_by.id', $data['agent']->id);

        $payment->refresh();
        $this->assertEquals('VERIFIED', $payment->status);
        $this->assertEquals($data['agent']->id, $payment->verified_by);
        $this->assertNotNull($payment->verified_at);
    }

    public function test_agent_can_reject_bank_transfer_slip_with_reason_on_web(): void
    {
        $data = $this->createTestData();

        $sale = Sale::create([
            'sale_code'    => 'SALE-20261018-0021',
            'rep_id'       => $data['rep']->id,
            'shop_id'      => $data['shop']->id,
            'branch_id'    => $data['branch']->id,
            'payment_type' => 'BANK_TRANSFER',
            'total_amount' => 2000.00,
            'paid_amount'  => 2000.00,
        ]);

        $payment = SalePayment::create([
            'sale_id'          => $sale->id,
            'bank_account_id'  => $data['bankAccount']->id,
            'payment_type'     => 'BANK_TRANSFER',
            'amount'           => 2000.00,
            'slip_image'       => 'bank_slips/bad_receipt.jpg',
            'status'           => 'PENDING',
        ]);

        Sanctum::actingAs($data['agent']);

        $response = $this->postJson("/api/v1/bank-transfers/{$payment->id}/verify", [
            'status'           => 'REJECTED',
            'rejection_reason' => 'Slip reference does not match bank records',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.rejection_reason', 'Slip reference does not match bank records');

        $payment->refresh();
        $this->assertEquals('REJECTED', $payment->status);
        $this->assertEquals('Slip reference does not match bank records', $payment->rejection_reason);
    }

    public function test_agent_can_filter_sales_by_bank_slip_on_web(): void
    {
        $data = $this->createTestData();

        // Sale 1: With bank slip
        $sale1 = Sale::create([
            'sale_code'    => 'SALE-SLIP-YES',
            'rep_id'       => $data['rep']->id,
            'payment_type' => 'BANK_TRANSFER',
            'total_amount' => 1000.00,
            'paid_amount'  => 1000.00,
        ]);
        SalePayment::create([
            'sale_id'         => $sale1->id,
            'bank_account_id' => $data['bankAccount']->id,
            'payment_type'    => 'BANK_TRANSFER',
            'amount'          => 1000.00,
            'slip_image'      => 'bank_slips/yes.jpg',
        ]);

        // Sale 2: Cash without slip
        $sale2 = Sale::create([
            'sale_code'    => 'SALE-SLIP-NO',
            'rep_id'       => $data['rep']->id,
            'payment_type' => 'CASH',
            'total_amount' => 500.00,
            'paid_amount'  => 500.00,
        ]);
        SalePayment::create([
            'sale_id'      => $sale2->id,
            'payment_type' => 'CASH',
            'amount'       => 500.00,
        ]);

        Sanctum::actingAs($data['agent']);

        $response = $this->getJson('/api/v1/sales?has_bank_slip=1');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $sales = $response->json('data');
        $this->assertCount(1, $sales);
        $this->assertEquals('SALE-SLIP-YES', $sales[0]['sale_code']);
        $this->assertNotNull($sales[0]['payments'][0]['slip_url']);
    }
}
