<?php

namespace Database\Seeders;

use App\Models\AcceptedRequestItem;
use App\Models\Branch;
use App\Models\Category;
use App\Models\DsrProfile;
use App\Models\Item;
use App\Models\RepStock;
use App\Models\Route;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SalesNetworkSeeder extends Seeder
{
    /**
     * Seed one branch, route, sales rep, and shop.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $branch = Branch::updateOrCreate(
                ['code' => 'BR-001'],
                [
                    'name' => 'Colombo Branch',
                    'address' => 'Colombo, Sri Lanka',
                    'total_warehouse_stock_value' => 0,
                ]
            );

            $route = Route::updateOrCreate(
                ['route_code' => 'RT-001'],
                [
                    'branch_id' => $branch->id,
                    'route_name' => 'Colombo Route 01',
                    'description' => 'Default seeded sales route',
                ]
            );

            $rep = User::updateOrCreate(
                ['email' => 'rep@hutch.lk'],
                [
                    'name' => 'Default Sales Rep',
                    'phone' => '+94770000001',
                    'password' => Hash::make('password'),
                    'role' => 'DSR_REP',
                    'status' => 'ACTIVE',
                    'email_verified_at' => now(),
                ]
            );

            DsrProfile::updateOrCreate(
                ['user_id' => $rep->id],
                [
                    'rep_code' => 'REP-001',
                    'branch_id' => $branch->id,
                    'assigned_route_id' => $route->id,
                    'basic_salary' => 0,
                    'bike_allowance' => 0,
                    'fuel_allowance' => 0,
                    'dsr_status' => 'ACTIVE',
                ]
            );

            $route->update(['assigned_referrer_id' => $rep->id]);

            Shop::updateOrCreate(
                ['shop_code' => 'SHP-001'],
                [
                    'route_id' => $route->id,
                    'created_by' => $rep->id,
                    'shop_name' => 'Colombo Communication',
                    'owner_name' => 'Default Shop Owner',
                    'phone' => '+94770000002',
                    'address' => 'Colombo, Sri Lanka',
                    'credit_limit' => 100000,
                    'current_credit_balance' => 0,
                    'status' => 'GOOD',
                ]
            );

            $category = Category::updateOrCreate(
                ['code' => 'TELCO'],
                [
                    'name' => 'Telecommunications',
                    'badge_color' => '#2563EB',
                    'status' => 'ACTIVE',
                ]
            );

            $items = [
                [
                    'code' => 'HUT-SIM-001',
                    'name' => 'Hutch SIM Starter Pack',
                    'purchase_price' => 150,
                    'selling_price' => 250,
                    'market_price' => 250,
                    'rep_quantity' => 10,
                ],
                [
                    'code' => 'HUT-RLD-100',
                    'name' => 'Hutch Reload Card - Rs. 100',
                    'purchase_price' => 95,
                    'selling_price' => 100,
                    'market_price' => 100,
                    'rep_quantity' => 25,
                ],
            ];

            $repStock = RepStock::updateOrCreate(
                ['rep_id' => $rep->id],
                ['total_value' => 0, 'status' => 'ACCEPTED']
            );

            $totalStockValue = 0;

            foreach ($items as $itemData) {
                $quantity = $itemData['rep_quantity'];
                unset($itemData['rep_quantity']);

                $item = Item::updateOrCreate(
                    ['code' => $itemData['code']],
                    [...$itemData, 'category_id' => $category->id, 'status' => 'ACTIVE']
                );

                AcceptedRequestItem::updateOrCreate(
                    [
                        'rep_stock_id' => $repStock->id,
                        'item_id' => $item->id,
                        'batch_number' => 'REP-OPENING-2026-10',
                    ],
                    ['quantity' => $quantity]
                );

                $totalStockValue += $quantity * (float) $item->selling_price;
            }

            $repStock->update(['total_value' => $totalStockValue]);
        });

        $this->command?->info('Branch, route, rep, shop, items, and rep stock seeded successfully!');
    }
}
