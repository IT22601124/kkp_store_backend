<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Stock;
use Illuminate\Database\Seeder;

class ItemStockSeeder extends Seeder
{
    /**
     * Seed sample items and their opening warehouse stock at every branch.
     */
    public function run(): void
    {
        $category = Category::firstOrCreate(
            ['code' => 'TELCO'],
            ['name' => 'Telecommunications', 'badge_color' => '#2563EB', 'status' => 'ACTIVE']
        );

        Branch::firstOrCreate(
            ['code' => 'WH-001'],
            ['name' => 'Main Warehouse', 'address' => 'Colombo, Sri Lanka']
        );
        $branches = Branch::all();

        $items = [
            ['name' => 'Hutch SIM Starter Pack', 'code' => 'HUT-SIM-001', 'purchase_price' => 150.00, 'selling_price' => 250.00, 'market_price' => 250.00, 'quantity' => 100],
            ['name' => 'Hutch Reload Card - Rs. 100', 'code' => 'HUT-RLD-100', 'purchase_price' => 95.00, 'selling_price' => 100.00, 'market_price' => 100.00, 'quantity' => 200],
            ['name' => 'Hutch Reload Card - Rs. 500', 'code' => 'HUT-RLD-500', 'purchase_price' => 475.00, 'selling_price' => 500.00, 'market_price' => 500.00, 'quantity' => 100],
            ['name' => 'Hutch 4G Router', 'code' => 'HUT-RTR-4G', 'purchase_price' => 8500.00, 'selling_price' => 9900.00, 'market_price' => 10500.00, 'quantity' => 20],
        ];

        foreach ($items as $itemData) {
            $quantity = $itemData['quantity'];
            unset($itemData['quantity']);

            $item = Item::updateOrCreate(
                ['code' => $itemData['code']],
                [...$itemData, 'category_id' => $category->id, 'status' => 'ACTIVE']
            );

            $unitCost = $item->purchase_price;

            foreach ($branches as $branch) {
                $stock = Stock::firstOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'referrer_id' => null,
                        'item_id' => $item->id,
                        'batch_number' => 'OPENING-2026-10',
                    ],
                    [
                        'quantity' => $quantity,
                        'reserved_quantity' => 0,
                        'unit_cost' => $unitCost,
                        'unit_price' => $item->selling_price,
                        'total_value' => $quantity * $unitCost,
                        'status' => 'IN_STOCK',
                        'last_audited_at' => now(),
                    ]
                );

                if ($stock->wasRecentlyCreated) {
                    $branch->increment('total_warehouse_stock_value', $stock->total_value);
                }
            }
        }
    }
}
