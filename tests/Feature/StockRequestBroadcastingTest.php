<?php

namespace Tests\Feature;

use App\Events\StockRequestCreated;
use App\Models\Branch;
use App\Models\Category;
use App\Models\DsrProfile;
use App\Models\Item;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockRequestBroadcastingTest extends TestCase
{
    use RefreshDatabase;

    protected User $rep;
    protected Branch $branch;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

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
            'batch_number' => 'BATCH-001',
            'quantity'     => 50,
            'unit_cost'    => 100.00,
            'unit_price'   => 150.00,
            'total_value'  => 7500.00,
        ]);

        $this->rep = User::create([
            'name'      => 'Saman Kumara',
            'email'     => 'saman@kkp.com',
            'phone'     => '0779998888',
            'password'  => Hash::make('password'),
            'role'      => 'DSR_REP',
            'status'    => 'ACTIVE',
            'fcm_token' => 'rep_fcm_token_123',
        ]);

        DsrProfile::create([
            'user_id'    => $this->rep->id,
            'rep_code'   => 'REP-COL-001',
            'branch_id'  => $this->branch->id,
            'dsr_status' => 'ACTIVE',
        ]);
    }

    public function test_rep_stock_request_dispatches_realtime_broadcast_event()
    {
        Event::fake([StockRequestCreated::class]);

        Sanctum::actingAs($this->rep);

        $response = $this->postJson('/api/stock-requests', [
            'items' => [
                [
                    'item_id'  => $this->item->id,
                    'quantity' => 20,
                ],
            ],
            'notes' => 'Need SIM cards for Pettah route',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Stock request submitted successfully',
            ]);

        Event::assertDispatched(StockRequestCreated::class, function ($event) {
            $channels = $event->broadcastOn();
            $this->assertCount(1, $channels);
            $this->assertEquals('stock-requests', $channels[0]->name);
            $this->assertEquals('stock.requested', $event->broadcastAs());

            $data = $event->broadcastWith();
            $this->assertEquals('Saman Kumara', $data['rep_name']);
            $this->assertEquals('REP-COL-001', $data['rep_code']);
            $this->assertEquals('Main Warehouse', $data['branch_name']);
            $this->assertEquals(1, $data['items_count']);
            $this->assertEquals(3000.0, $data['total_value']); // 20 * 150.00

            return true;
        });
    }
}
