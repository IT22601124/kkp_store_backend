<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\DsrProfile;
use App\Models\Item;
use App\Models\Stock;
use App\Models\StockRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockIssueNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $supervisor;
    protected User $rep;
    protected Branch $branch;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.firebase.credentials' => '/path/does/not/exist.json']);
        config(['services.firebase.server_key' => 'test-server-key-fcm123']);

        $this->supervisor = User::create([
            'name'     => 'Warehouse Supervisor',
            'email'    => 'supervisor@kkp.com',
            'phone'    => '0711111111',
            'password' => Hash::make('password'),
            'role'     => 'AGENT',
            'status'   => 'ACTIVE',
        ]);

        $this->branch = Branch::create([
            'name'                        => 'Main Warehouse',
            'code'                        => 'WH-001',
            'address'                     => 'Colombo',
            'total_warehouse_stock_value' => 50000.00,
        ]);

        $category = Category::create([
            'name' => 'SIM Cards',
            'code' => 'SIM',
        ]);

        $this->item = Item::create([
            'name'           => 'Hutch 4G SIM Card',
            'code'           => 'SIM-001',
            'category_id'    => $category->id,
            'purchase_price' => 100.00,
            'selling_price'  => 150.00,
            'market_price'   => 150.00,
            'status'         => 'ACTIVE',
        ]);

        Stock::create([
            'branch_id'    => $this->branch->id,
            'referrer_id'  => null,
            'item_id'      => $this->item->id,
            'batch_number' => 'BATCH-WH-001',
            'quantity'     => 100,
            'unit_cost'    => 100.00,
            'unit_price'   => 150.00,
            'total_value'  => 15000.00,
        ]);

        $this->rep = User::create([
            'name'      => 'Saman Kumara',
            'email'     => 'saman@kkp.com',
            'phone'     => '0779998888',
            'password'  => Hash::make('password'),
            'role'      => 'DSR_REP',
            'status'    => 'ACTIVE',
            'fcm_token' => 'fcm_token_saman_device_abc999',
        ]);

        DsrProfile::create([
            'user_id'    => $this->rep->id,
            'rep_code'   => 'REP-COL-001',
            'branch_id'  => $this->branch->id,
            'dsr_status' => 'ACTIVE',
        ]);
    }

    public function test_stock_issue_for_rep_triggers_firebase_notification_when_rep_has_fcm_token()
    {
        Sanctum::actingAs($this->supervisor);

        Http::fake([
            'https://fcm.googleapis.com/fcm/send' => Http::response([
                'multicast_id' => 11223344,
                'success'      => 1,
                'failure'      => 0,
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/rep-stocks/issue-stock', [
            'to_referrer_id' => $this->rep->id,
            'from_branch_id' => $this->branch->id,
            'items'          => [
                [
                    'item_id'      => $this->item->id,
                    'quantity'     => 10,
                    'batch_number' => 'BATCH-WH-001',
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Stock issued to rep successfully',
            ]);

        // Verify Firebase Push Notification was dispatched
        Http::assertSent(function ($request) {
            return $request->url() === 'https://fcm.googleapis.com/fcm/send'
                && $request['to'] === 'fcm_token_saman_device_abc999'
                && str_contains($request['notification']['title'], 'New Stock Issued')
                && str_contains($request['notification']['body'], '10 unit')
                && $request['data']['type'] === 'stock_issued';
        });
    }

    public function test_stock_transfer_triggers_firebase_notification_when_rep_has_fcm_token()
    {
        Sanctum::actingAs($this->supervisor);

        Http::fake([
            'https://fcm.googleapis.com/fcm/send' => Http::response([
                'multicast_id' => 55667788,
                'success'      => 1,
                'failure'      => 0,
            ], 200),
        ]);

        $response = $this->postJson('/api/stock-transfers', [
            'to_referrer_id' => $this->rep->id,
            'from_branch_id' => $this->branch->id,
            'items'          => [
                [
                    'item_id'      => $this->item->id,
                    'quantity'     => 5,
                    'batch_number' => 'BATCH-WH-001',
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Stock transfer transmitted successfully',
            ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://fcm.googleapis.com/fcm/send'
                && $request['to'] === 'fcm_token_saman_device_abc999'
                && str_contains($request['notification']['title'], 'New Stock Issued')
                && $request['data']['type'] === 'stock_issued';
        });
    }

    public function test_stock_issue_succeeds_gracefully_when_rep_has_no_fcm_token()
    {
        Sanctum::actingAs($this->supervisor);

        $this->rep->update(['fcm_token' => null]);

        Http::fake();

        $response = $this->postJson('/api/v1/rep-stocks/issue-stock', [
            'to_referrer_id' => $this->rep->id,
            'from_branch_id' => $this->branch->id,
            'items'          => [
                [
                    'item_id'  => $this->item->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertStatus(200);

        // No push notification should be dispatched if rep has no token
        Http::assertNothingSent();
    }

    public function test_stock_request_approval_triggers_firebase_notification()
    {
        Sanctum::actingAs($this->supervisor);

        $stockRequest = StockRequest::create([
            'request_code' => 'REQ-20261011-0001',
            'rep_id'       => $this->rep->id,
            'branch_id'    => $this->branch->id,
            'request_date' => now(),
            'status'       => 'PENDING',
        ]);

        Http::fake([
            'https://fcm.googleapis.com/fcm/send' => Http::response([
                'success' => 1,
            ], 200),
        ]);

        $response = $this->postJson('/api/stock-requests/status', [
            'request_id' => $stockRequest->id,
            'status'     => 'APPROVED',
        ]);

        $response->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://fcm.googleapis.com/fcm/send'
                && $request['to'] === 'fcm_token_saman_device_abc999'
                && str_contains($request['notification']['title'], 'Stock Request Approved');
        });
    }
}
