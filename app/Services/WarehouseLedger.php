<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Validation\ValidationException;

class WarehouseLedger
{
    /** Called inside a transaction. Branch locks serialize mutations while preserving batches. */
    public function change(int $branchId, Item $item, int $delta, ?string $batch, string $type,
        string $referenceType, int $referenceId, string $actor, ?float $cost = null): array
    {
        Branch::whereKey($branchId)->lockForUpdate()->firstOrFail();
        $query = Stock::where('branch_id', $branchId)->whereNull('referrer_id')->where('item_id', $item->id);
        if ($batch !== null) {
            $query->where('batch_number', $batch);
        }
        $rows = $query->orderBy('id')->lockForUpdate()->get();
        $changes = [];
        if ($delta < 0 && $rows->sum(fn ($row) => max(0, $row->quantity - $row->reserved_quantity)) < -$delta) {
            throw ValidationException::withMessages(['items' => 'Insufficient unreserved warehouse stock for '.$item->name.'.']);
        }
        if ($delta > 0) {
            $stock = $rows->first() ?? Stock::create(['branch_id' => $branchId, 'referrer_id' => null,
                'item_id' => $item->id, 'batch_number' => $batch, 'quantity' => 0,
                'unit_cost' => $cost ?? $item->purchase_price, 'unit_price' => $item->selling_price, 'total_value' => 0]);
            $rows = collect([$stock]);
        }
        $remaining = abs($delta);
        foreach ($rows as $row) {
            $amount = $delta > 0 ? $remaining : min($remaining, max(0, $row->quantity - $row->reserved_quantity));
            if (!$amount) {
                continue;
            }
            $before = $row->quantity;
            $change = $delta > 0 ? $amount : -$amount;
            $row->quantity += $change;
            if ($cost !== null && $delta > 0) {
                $row->unit_cost = $cost;
            }
            $row->total_value = $row->quantity * $row->unit_price;
            $row->save();
            StockMovement::create(['stock_id' => $row->id, 'item_id' => $item->id, 'branch_id' => $branchId,
                'movement_type' => $type, 'quantity_before' => $before, 'quantity_change' => $change,
                'quantity_after' => $row->quantity, 'reference_type' => $referenceType,
                'reference_id' => $referenceId, 'performed_by' => $actor]);
            $changes[] = ['stock_id' => $row->id, 'batch_number' => $row->batch_number, 'quantity' => $amount];
            $remaining -= $amount;
            if ($remaining === 0) {
                break;
            }
        }
        Branch::whereKey($branchId)->update(['total_warehouse_stock_value' => Stock::where('branch_id', $branchId)
            ->whereNull('referrer_id')->sum('total_value')]);

        return $changes;
    }
}
