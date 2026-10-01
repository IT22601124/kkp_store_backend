<?php

namespace Database\Seeders;

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
        $rep = User::where('email', 'test@example.com')->firstOrFail();
        $branchId = $rep->dsrProfile?->branch_id;

        if (!$branchId) {
            $this->command?->warn('Skipping rep stock seed: test rep has no assigned branch.');

            return;
        }

        $quantities = [
            'HUT-SIM-001' => 10,
            'HUT-RLD-100' => 25,
            'HUT-RLD-500' => 15,
            'HUT-RTR-4G' => 3,
        ];

        foreach ($quantities as $code => $quantity) {
            $item = Item::where('code', $code)->first();

            if (!$item) {
                continue;
            }

            RepStock::firstOrCreate(
                [
                    'rep_id' => $rep->id,
                    'item_id' => $item->id,
                    'batch_number' => 'REP-OPENING-2026-10',
                ],
                [
                    'branch_id' => $branchId,
                    'quantity' => $quantity,
                    'reserved_quantity' => 0,
                    'unit_cost' => $item->purchase_price,
                    'unit_price' => $item->selling_price,
                    'total_value' => $quantity * $item->selling_price,
                    'status' => 'IN_STOCK',
                    'last_synced_at' => now(),
                    'last_audited_at' => now(),
                ]
            );
        }
    }
}
