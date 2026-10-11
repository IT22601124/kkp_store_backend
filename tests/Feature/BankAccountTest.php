<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Item;
use App\Models\User;
use Database\Seeders\BankAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BankAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_4_agent_bank_accounts(): void
    {
        $this->seed(BankAccountSeeder::class);

        $this->assertDatabaseCount('bank_accounts', 4);

        $this->assertDatabaseHas('bank_accounts', [
            'bank_name' => 'Commercial Bank of Ceylon',
            'is_default' => 1,
            'is_active' => 1,
        ]);

        $this->assertDatabaseHas('bank_accounts', [
            'bank_name' => 'Sampath Bank',
            'is_default' => 0,
        ]);

        $this->assertDatabaseHas('bank_accounts', [
            'bank_name' => 'Bank of Ceylon (BOC)',
            'is_default' => 0,
        ]);

        $this->assertDatabaseHas('bank_accounts', [
            'bank_name' => 'Hatton National Bank (HNB)',
            'is_default' => 0,
        ]);
    }

    public function test_can_list_agent_bank_accounts(): void
    {
        $this->seed(BankAccountSeeder::class);

        $agent = User::factory()->create(['role' => 'AGENT']);
        Sanctum::actingAs($agent);

        $response = $this->getJson('/api/v1/bank-accounts');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(4, 'data');
    }

    public function test_can_get_active_bank_accounts(): void
    {
        $this->seed(BankAccountSeeder::class);

        $user = User::factory()->create(['role' => 'DSR_REP']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/bank-accounts/active');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(4, 'data');
    }

    public function test_can_create_new_bank_account(): void
    {
        $agent = User::factory()->create(['role' => 'AGENT']);
        Sanctum::actingAs($agent);

        $payload = [
            'bank_name' => 'National Development Bank (NDB)',
            'account_number' => '109988776655',
            'account_name' => 'KKP Hutchison Agency - NDB',
            'branch_name' => 'Nawala Branch',
            'account_type' => 'CURRENT',
            'opening_balance' => 25000.00,
            'is_default' => false,
            'is_active' => true,
        ];

        $response = $this->postJson('/api/v1/bank-accounts', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.bank_name', 'National Development Bank (NDB)')
            ->assertJsonPath('data.account_number', '109988776655');

        $this->assertDatabaseHas('bank_accounts', [
            'bank_name' => 'National Development Bank (NDB)',
            'account_number' => '109988776655',
        ]);
    }

    public function test_can_set_default_account(): void
    {
        $this->seed(BankAccountSeeder::class);

        $agent = User::factory()->create(['role' => 'AGENT']);
        Sanctum::actingAs($agent);

        $sampath = BankAccount::where('bank_name', 'Sampath Bank')->firstOrFail();

        $response = $this->patchJson("/api/v1/bank-accounts/{$sampath->id}/set-default");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_default', true);

        // Previous default should now be false
        $this->assertDatabaseHas('bank_accounts', [
            'bank_name' => 'Commercial Bank of Ceylon',
            'is_default' => 0,
        ]);

        $this->assertDatabaseHas('bank_accounts', [
            'id' => $sampath->id,
            'is_default' => 1,
        ]);
    }

    public function test_can_toggle_bank_account_status(): void
    {
        $this->seed(BankAccountSeeder::class);

        $agent = User::factory()->create(['role' => 'AGENT']);
        Sanctum::actingAs($agent);

        $hnb = BankAccount::where('bank_name', 'Hatton National Bank (HNB)')->firstOrFail();

        $response = $this->patchJson("/api/v1/bank-accounts/{$hnb->id}/toggle-status");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('bank_accounts', [
            'id' => $hnb->id,
            'is_active' => 0,
        ]);
    }

    public function test_can_get_bank_account_summary(): void
    {
        $this->seed(BankAccountSeeder::class);

        $agent = User::factory()->create(['role' => 'AGENT']);
        Sanctum::actingAs($agent);

        $response = $this->getJson('/api/v1/bank-accounts-summary');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_accounts_count', 4)
            ->assertJsonPath('data.active_accounts_count', 4)
            ->assertJsonPath('data.total_balance', fn ($val) => (float)$val == 375000.00);
    }

    public function test_can_record_deposit_into_bank_account(): void
    {
        $this->seed(BankAccountSeeder::class);

        $agent = User::factory()->create(['role' => 'AGENT']);
        Sanctum::actingAs($agent);

        $boc = BankAccount::where('bank_name', 'Bank of Ceylon (BOC)')->firstOrFail();
        $initialBalance = (float) $boc->current_balance;

        $response = $this->postJson('/api/v1/bank-deposits', [
            'bank_account_id' => $boc->id,
            'amount' => 15000.00,
            'slip_reference' => 'BOC-SLIP-998822',
            'notes' => 'Afternoon reload bank deposit',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.deposit.amount', '15000.00')
            ->assertJsonPath('data.updated_balance', fn ($val) => (float)$val == ($initialBalance + 15000.00));

        $this->assertDatabaseHas('dsr_bank_deposits', [
            'bank_account_id' => $boc->id,
            'amount' => 15000.00,
            'slip_reference' => 'BOC-SLIP-998822',
        ]);
    }

    public function test_sale_payment_links_to_bank_account_and_updates_balance(): void
    {
        $this->seed(BankAccountSeeder::class);

        $rep = User::factory()->create(['role' => 'DSR_REP']);
        Sanctum::actingAs($rep);

        $commBank = BankAccount::where('bank_name', 'Commercial Bank of Ceylon')->firstOrFail();
        $initialBalance = (float) $commBank->current_balance;

        $category = Category::create(['name' => 'Data Cards', 'code' => 'DC']);
        $item = Item::create([
            'name' => '4G SIM Card',
            'code' => 'SIM-4G',
            'category_id' => $category->id,
            'purchase_price' => 100.00,
            'selling_price' => 200.00,
            'status' => 'ACTIVE',
        ]);

        $response = $this->postJson('/api/v1/sales', [
            'payment_type' => 'ONLINE',
            'bank_account_id' => $commBank->id,
            'paid_amount' => 1000.00,
            'items' => [
                [
                    'item_id' => $item->id,
                    'quantity' => 5,
                    'unit_price' => 200.00,
                ],
            ],
            'payments' => [
                [
                    'payment_type' => 'ONLINE',
                    'bank_account_id' => $commBank->id,
                    'amount' => 1000.00,
                    'reference_number' => 'REF-COMM-ONLINE-001',
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payments.0.bank_account_id', $commBank->id)
            ->assertJsonPath('data.payments.0.bank_account.bank_name', 'Commercial Bank of Ceylon');

        $this->assertDatabaseHas('sale_payments', [
            'bank_account_id' => $commBank->id,
            'amount' => 1000.00,
        ]);

        // Commercial bank balance should increase by 1000.00
        $commBank->refresh();
        $this->assertEquals($initialBalance + 1000.00, (float) $commBank->current_balance);
    }
}
