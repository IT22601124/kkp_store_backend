<?php

namespace Database\Seeders;

use App\Models\AcceptedRequestItem;
use App\Models\Item;
use App\Models\RepStock;
use App\Models\User;
use Illuminate\Database\Seeder;

class RepStockSeeder extends Seeder
{
    /**
     * Seed opening inventory for the local demo sales rep.
     */
    public function run(): void
    {
        $rep = User::where('email', 'test@example.com')->first();
        if (!$rep) {
            return;
        }

        $repStock = RepStock::create([
            'rep_id' => $rep->id,
            'total_value' => 0.00,
            'status' => 'ACCEPTED',
        ]);

        $quantities = [
            'HUT-SIM-001' => 10,
            'HUT-RLD-100' => 25,
            'HUT-RLD-500' => 15,
            'HUT-RTR-4G' => 3,
        ];

        $totalVal = 0;
        foreach ($quantities as $code => $quantity) {
            $item = Item::where('code', $code)->first();
            if (!$item) {
                continue;
            }

            $subtotal = $quantity * $item->selling_price;
            $totalVal += $subtotal;

            AcceptedRequestItem::create([
                'rep_stock_id' => $repStock->id,
                'item_id' => $item->id,
                'quantity' => $quantity,
                'batch_number' => 'REP-OPENING-2026-10',
            ]);
        }

        $repStock->update(['total_value' => $totalVal]);
    }
}
