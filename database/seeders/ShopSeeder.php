<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Shop;

class ShopSeeder extends Seeder
{
    public function run(): void
    {
        if (Shop::count() === 0) {
            Shop::create([
                'route_id' => 1,
                'shop_code' => 'SHP-101',
                'shop_name' => 'Welimada Communication & Reload',
                'owner_name' => 'K. Wickramasinghe',
                'phone' => '+94 77 123 4567',
                'address' => 'Main Street, Welimada',
                'credit_limit' => 100000,
                'current_credit_balance' => 35000,
                'status' => 'GOOD'
            ]);

            Shop::create([
                'route_id' => 2,
                'shop_code' => 'SHP-102',
                'shop_name' => 'Passara New Grocery & Super',
                'owner_name' => 'S. Periyasamy',
                'phone' => '+94 71 987 6543',
                'address' => 'Clock Tower Junction, Passara',
                'credit_limit' => 150000,
                'current_credit_balance' => 125000,
                'status' => 'OVERDUE'
            ]);

            Shop::create([
                'route_id' => 3,
                'shop_code' => 'SHP-103',
                'shop_name' => 'Bandarawela Mobile Hub',
                'owner_name' => 'M. Rizwan',
                'phone' => '+94 76 555 4321',
                'address' => 'Badulla Road, Bandarawela',
                'credit_limit' => 200000,
                'current_credit_balance' => 45000,
                'status' => 'GOOD'
            ]);
        }
    }
}
