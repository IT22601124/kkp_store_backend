<?php

namespace App\Services;

use App\Models\AcceptedRequestItem;
use App\Models\RepStock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepInventory
{
    /** Called inside the sale transaction. Lock the owner before stock rows to serialize rep sales. */
    public function consume(int $repId, array $lines, int $saleId): void
    {
        User::whereKey($repId)->lockForUpdate()->firstOrFail();
        $stocks = RepStock::where('rep_id', $repId)->whereIn('status', ['APPROVED', 'ACCEPTED', 'ACTIVE'])
            ->orderBy('id')->lockForUpdate()->get();

        foreach ($lines as $line) {
            $query = AcceptedRequestItem::whereIn('rep_stock_id', $stocks->modelKeys())
                ->where('item_id', $line['item_id'])->where('quantity', '>', 0);
            if (! empty($line['batch_number'])) {
                $query->where('batch_number', $line['batch_number']);
            }
            $rows = $query->orderBy('id')->lockForUpdate()->get();
            $remaining = (int) $line['quantity'];
            if ($rows->sum('quantity') < $remaining) {
                throw ValidationException::withMessages(['items' => "Insufficient issued stock for item {$line['item_id']}."]);
            }
            foreach ($rows as $row) {
                $taken = min($remaining, $row->quantity);
                $row->decrement('quantity', $taken);
                DB::table('sale_stock_allocations')->insert([
                    'sale_id' => $saleId, 'accepted_request_item_id' => $row->id, 'quantity' => $taken,
                ]);
                $remaining -= $taken;
                if ($remaining === 0) {
                    break;
                }
            }
        }

        foreach ($stocks as $stock) {
            $stock->update(['total_value' => $stock->items()->with('item')->get()
                ->sum(fn ($line) => $line->quantity * (float) ($line->item?->selling_price ?? 0))]);
        }
    }

    public function restore(int $repId, int $saleId): void
    {
        User::whereKey($repId)->lockForUpdate()->firstOrFail();
        $allocations = DB::table('sale_stock_allocations')->where('sale_id', $saleId)->orderBy('id')->lockForUpdate()->get();
        foreach ($allocations as $allocation) {
            AcceptedRequestItem::whereKey($allocation->accepted_request_item_id)->increment('quantity', $allocation->quantity);
        }
        DB::table('sale_stock_allocations')->where('sale_id', $saleId)->delete();
        foreach (RepStock::where('rep_id', $repId)->orderBy('id')->lockForUpdate()->get() as $stock) {
            $stock->update(['total_value' => $stock->items()->with('item')->get()
                ->sum(fn ($line) => $line->quantity * (float) ($line->item?->selling_price ?? 0))]);
        }
    }
}
