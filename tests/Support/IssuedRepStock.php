<?php

namespace Tests\Support;

use App\Models\AcceptedRequestItem;
use App\Models\Branch;
use App\Models\DsrProfile;
use App\Models\Item;
use App\Models\RepStock;
use App\Models\Route;
use App\Models\User;

trait IssuedRepStock
{
    protected function seedIssuedStock(User $rep, Item $item, ?Branch $branch = null, ?Route $route = null): void
    {
        $branch ??= Branch::create(['name' => 'Rep branch', 'code' => 'REP-'.$rep->id]);
        DsrProfile::updateOrCreate(['user_id' => $rep->id], ['rep_code' => 'REP-'.$rep->id,
            'branch_id' => $branch->id, 'assigned_route_id' => $route?->id, 'dsr_status' => 'ACTIVE']);
        $stock = RepStock::create(['rep_id' => $rep->id, 'status' => 'APPROVED', 'total_value' => 100 * $item->selling_price]);
        AcceptedRequestItem::create(['rep_stock_id' => $stock->id, 'item_id' => $item->id, 'quantity' => 100]);
    }
}
