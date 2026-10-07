<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_has_many_sales_items_relationship(): void
    {
        $user = User::factory()->create(['role' => 'DSR_REP']);
        $branch = Branch::create(['name' => 'Main Branch', 'code' => 'BR001']);
        $category = Category::create(['name' => 'Beverages', 'code' => 'BEV']);
        $item1 = Item::create([
            'name' => 'Coca Cola 500ml',
            'code' => 'COKE-500',
            'category_id' => $category->id,
            'purchase_price' => 100.00,
            'selling_price' => 150.00,
            'status' => 'ACTIVE',
        ]);
        $item2 = Item::create([
            'name' => 'Pepsi 500ml',
            'code' => 'PEP-500',
            'category_id' => $category->id,
            'purchase_price' => 90.00,
            'selling_price' => 140.00,
            'status' => 'ACTIVE',
        ]);

        $sale = Sale::create([
            'sale_code' => 'SALE-20261007-00001',
            'rep_id' => $user->id,
            'branch_id' => $branch->id,
            'payment_type' => 'CASH',
            'status' => 'COMPLETED',
            'subtotal' => 430.00,
            'total_amount' => 430.00,
            'paid_amount' => 430.00,
        ]);

        $saleItem1 = SaleItem::create([
            'sale_id' => $sale->id,
            'item_id' => $item1->id,
            'quantity' => 2,
            'unit_price' => 150.00,
            'subtotal' => 300.00,
        ]);

        $saleItem2 = SaleItem::create([
            'sale_id' => $sale->id,
            'item_id' => $item2->id,
            'quantity' => 1,
            'unit_price' => 130.00,
            'subtotal' => 130.00,
        ]);

        $this->assertCount(2, $sale->salesItems);
        $this->assertCount(2, $sale->items);
        $this->assertEquals($sale->id, $saleItem1->sale->id);
        $this->assertEquals($item1->id, $saleItem1->item->id);
    }

    public function test_can_create_sale_via_api(): void
    {
        $user = User::factory()->create(['role' => 'DSR_REP']);
        Sanctum::actingAs($user);

        $category = Category::create(['name' => 'Snacks', 'code' => 'SNK']);
        $item = Item::create([
            'name' => 'Chips',
            'code' => 'CHP-01',
            'category_id' => $category->id,
            'purchase_price' => 50.00,
            'selling_price' => 80.00,
            'status' => 'ACTIVE',
        ]);

        $response = $this->postJson('/api/v1/sales', [
            'payment_type' => 'CASH',
            'items' => [
                [
                    'item_id' => $item->id,
                    'quantity' => 5,
                    'unit_price' => 80.00,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sales_items.0.item_id', $item->id)
            ->assertJsonPath('data.sales_items.0.quantity', 5);

        $this->assertDatabaseHas('sales', [
            'rep_id' => $user->id,
            'total_amount' => 400.00,
        ]);

        $this->assertDatabaseHas('sales_items', [
            'item_id' => $item->id,
            'quantity' => 5,
            'subtotal' => 400.00,
        ]);
    }
}
