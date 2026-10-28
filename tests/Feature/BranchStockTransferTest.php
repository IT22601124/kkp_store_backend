<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStockTransfer;
use App\Models\Category;
use App\Models\Item;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchStockTransferTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $agent = User::factory()->create(['role' => 'AGENT']);
        Sanctum::actingAs($agent);
        $from = Branch::create(['name' => 'Colombo', 'code' => 'CMB', 'total_warehouse_stock_value' => 2000]);
        $to = Branch::create(['name' => 'Kandy', 'code' => 'KDY']);
        $category = Category::create(['name' => 'Cards', 'code' => 'CARD']);
        $item = Item::create(['name' => 'Card', 'code' => 'C1', 'category_id' => $category->id,
            'purchase_price' => 50, 'selling_price' => 100, 'status' => 'ACTIVE']);
        $stock = Stock::create(['branch_id' => $from->id, 'item_id' => $item->id, 'batch_number' => 'B1',
            'quantity' => 20, 'reserved_quantity' => 2, 'unit_cost' => 50, 'unit_price' => 100, 'total_value' => 2000]);
        $payload = ['request_key' => (string) Str::uuid(), 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'items' => [['stock_id' => $stock->id, 'quantity' => 7]], 'notes' => 'Warehouse replenishment'];
        return compact('agent', 'from', 'to', 'item', 'stock', 'payload');
    }

    public function test_transfer_moves_the_exact_batch_preserves_values_and_retries_once(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/branch-stock-transfers', $f['payload'])->assertCreated()
            ->assertJsonPath('data.from_branch.name', 'Colombo')->assertJsonPath('data.to_branch.name', 'Kandy')
            ->assertJsonPath('data.items.0.batch_number', 'B1')->json('data.id');
        $this->postJson('/api/v1/branch-stock-transfers', $f['payload'])->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertEquals(13, $f['stock']->fresh()->quantity);
        $this->assertEquals(2, $f['stock']->fresh()->reserved_quantity);
        $destination = Stock::where('branch_id', $f['to']->id)->sole();
        $this->assertEquals(7, $destination->quantity);
        $this->assertEquals(50, $destination->unit_cost);
        $this->assertEquals(100, $destination->unit_price);
        $this->assertEquals(20, Stock::sum('quantity'));
        $this->assertEquals(2000, Stock::sum('total_value'));
        $this->assertEquals(1300, $f['from']->fresh()->total_warehouse_stock_value);
        $this->assertEquals(700, $f['to']->fresh()->total_warehouse_stock_value);
        $this->assertDatabaseCount('branch_stock_transfers', 1);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertEquals(-7, StockMovement::where('movement_type', 'TRANSFER_OUT')->sole()->quantity_change);
        $this->assertEquals(7, StockMovement::where('movement_type', 'TRANSFER_IN')->sole()->quantity_change);
        $this->getJson('/api/v1/branch-stock-transfers')->assertOk()->assertJsonPath('data.0.notes', 'Warehouse replenishment');
        $f['payload']['items'][0]['quantity'] = 8;
        $this->postJson('/api/v1/branch-stock-transfers', $f['payload'])->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->assertEquals(20, Stock::sum('quantity'));
    }

    public function test_insufficient_reserved_wrong_branch_and_duplicate_stock_cannot_be_transferred(): void
    {
        $f = $this->fixture();
        foreach ([
            ['items' => [['stock_id' => $f['stock']->id, 'quantity' => 19]]],
            ['to_branch_id' => $f['from']->id],
            ['from_branch_id' => $f['to']->id, 'to_branch_id' => $f['from']->id],
            ['items' => [['stock_id' => $f['stock']->id, 'quantity' => 0]]],
            ['items' => [['stock_id' => $f['stock']->id, 'quantity' => 1.5]]],
            ['items' => [$f['payload']['items'][0], $f['payload']['items'][0]]],
        ] as $change) {
            $this->postJson('/api/v1/branch-stock-transfers', array_replace($f['payload'], $change))->assertUnprocessable();
        }
        $this->assertEquals(20, $f['stock']->fresh()->quantity);
        $this->assertDatabaseCount('branch_stock_transfers', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stocks', 1);
    }

    public function test_multi_line_failure_rolls_back_every_line_and_rep_stock_is_rejected(): void
    {
        $f = $this->fixture();
        $rep = User::factory()->create(['role' => 'DSR_REP']);
        $repStock = Stock::create(['branch_id' => $f['from']->id, 'referrer_id' => $rep->id, 'item_id' => $f['item']->id,
            'batch_number' => 'REP', 'quantity' => 10, 'unit_cost' => 50, 'unit_price' => 100, 'total_value' => 1000]);
        $f['payload']['items'][] = ['stock_id' => $repStock->id, 'quantity' => 1];
        $this->postJson('/api/v1/branch-stock-transfers', $f['payload'])->assertUnprocessable();
        $this->assertEquals(20, $f['stock']->fresh()->quantity);
        $this->assertEquals(10, $repStock->fresh()->quantity);
        $this->assertDatabaseCount('branch_stock_transfers', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_destination_batch_merges_only_when_cost_and_price_match_and_reverse_transfer_works(): void
    {
        $f = $this->fixture();
        $destination = Stock::create(['branch_id' => $f['to']->id, 'item_id' => $f['item']->id, 'batch_number' => 'B1',
            'quantity' => 5, 'unit_cost' => 40, 'unit_price' => 90, 'total_value' => 450]);
        $this->postJson('/api/v1/branch-stock-transfers', $f['payload'])->assertCreated();
        $this->assertEquals(5, $destination->fresh()->quantity);
        $newBatch = Stock::where('branch_id', $f['to']->id)->where('unit_price', 100)->sole();
        $f['payload']['request_key'] = (string) Str::uuid();
        $this->postJson('/api/v1/branch-stock-transfers', $f['payload'])->assertCreated();
        $this->assertEquals(14, $newBatch->fresh()->quantity);
        $this->assertEquals(2, Stock::where('branch_id', $f['to']->id)->count());
        $this->postJson('/api/v1/branch-stock-transfers', ['request_key' => (string) Str::uuid(),
            'from_branch_id' => $f['to']->id, 'to_branch_id' => $f['from']->id,
            'items' => [['stock_id' => $newBatch->id, 'quantity' => 4]]])->assertCreated();
        $this->assertEquals(10, $f['stock']->fresh()->quantity);
        $this->assertEquals(10, $newBatch->fresh()->quantity);
        $this->assertEquals(2450, Stock::sum('total_value'));
    }

    public function test_warehouse_transfers_require_an_agent_account(): void
    {
        $f = $this->fixture();
        Sanctum::actingAs(User::factory()->create(['role' => 'DSR_REP']));
        $this->postJson('/api/v1/branch-stock-transfers', $f['payload'])->assertForbidden();
        $this->getJson('/api/v1/branch-stock-transfers')->assertForbidden();
        $this->assertDatabaseCount('branch_stock_transfers', 0);
    }
}
