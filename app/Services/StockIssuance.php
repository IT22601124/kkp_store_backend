<?php

namespace App\Services;

use App\Models\AcceptedRequestItem;
use App\Models\Branch;
use App\Models\Item;
use App\Models\RepStock;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockIssuance
{
    public function issue(int $branchId, int $repId, array $items, User $actor, ?int $requestId = null,
        ?string $requestKey = null, ?string $notes = null): array
    {
        return DB::transaction(function () use ($branchId, $repId, $items, $actor, $requestId, $requestKey, $notes) {
            $rep = User::whereKey($repId)->lockForUpdate()->firstOrFail();
            if ($rep->role !== 'DSR_REP' || (int) $rep->dsrProfile?->branch_id !== $branchId) {
                throw ValidationException::withMessages(['rep_id' => 'Select a representative assigned to the source branch.']);
            }
            Branch::whereKey($branchId)->lockForUpdate()->firstOrFail();
            $normalized = array_map(fn ($line) => [(int) $line['item_id'], (int) $line['quantity'], $line['batch_number'] ?? null], $items);
            sort($normalized);
            $hash = hash('sha256', json_encode([$branchId, $repId, $normalized]));
            $existing = StockTransfer::query()->where(function ($query) use ($requestId, $requestKey) {
                $query->whereRaw('1=0');
                if ($requestId) $query->orWhere('request_id', $requestId);
                if ($requestKey) $query->orWhere('request_key', $requestKey);
            })->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    throw ValidationException::withMessages(['items' => 'This issuance request has already been fulfilled with different items.']);
                }
                return [$existing->repStock->load(['rep', 'items.item']), $existing->load(['fromBranch', 'toReferrer', 'items.item'])];
            }
            $request = $requestId ? StockRequest::whereKey($requestId)->lockForUpdate()->firstOrFail() : null;
            if ($request && ((int) $request->rep_id !== $repId || (int) $request->branch_id !== $branchId || $request->status === 'REJECTED')) {
                throw ValidationException::withMessages(['request_id' => 'The request cannot be issued to this representative/branch.']);
            }
            $request ??= StockRequest::create(['request_code' => 'REQ-'.strtoupper(bin2hex(random_bytes(8))),
                'branch_id' => $branchId, 'rep_id' => $repId, 'status' => 'PENDING', 'notes' => $notes ?? 'Direct issuance']);
            $repStock = RepStock::create(['rep_id' => $repId, 'status' => 'APPROVED', 'total_value' => 0]);
            $transfer = StockTransfer::create(['transfer_code' => 'TRF-'.strtoupper(bin2hex(random_bytes(8))),
                'request_id' => $request->id, 'rep_stock_id' => $repStock->id, 'request_key' => $requestKey, 'request_hash' => $hash,
                'from_branch_id' => $branchId, 'to_referrer_id' => $repId, 'transfer_date' => now(), 'status' => 'ACCEPTED',
                'total_value' => 0, 'notes' => $notes]);
            $total = 0;
            foreach ($items as $line) {
                $item = Item::findOrFail($line['item_id']);
                $batches = app(WarehouseLedger::class)->change($branchId, $item, -(int) $line['quantity'], $line['batch_number'] ?? null,
                    'TRANSFER_OUT', StockTransfer::class, $transfer->id, $actor->name);
                foreach ($batches as $batch) {
                    AcceptedRequestItem::create(['rep_stock_id' => $repStock->id, 'item_id' => $item->id,
                        'quantity' => $batch['quantity'], 'batch_number' => $batch['batch_number']]);
                    $transfer->items()->create(['item_id' => $item->id, 'quantity' => $batch['quantity'],
                        'batch_number' => $batch['batch_number'], 'unit_price' => $item->selling_price,
                        'subtotal' => $batch['quantity'] * (float) $item->selling_price]);
                    $total += $batch['quantity'] * (float) $item->selling_price;
                }
            }
            foreach (collect($items)->groupBy('item_id') as $itemId => $lines) {
                $quantity = $lines->sum('quantity');
                $requestLine = $request->items()->where('item_id', $itemId)->first();
                if ($requestLine) {
                    $requestLine->update(['approved_quantity' => $quantity]);
                } else {
                    $request->items()->create(['item_id' => $itemId, 'requested_quantity' => $quantity, 'approved_quantity' => $quantity]);
                }
            }
            $request->update(['status' => 'APPROVED']);
            $repStock->update(['total_value' => $total]);
            $transfer->update(['total_value' => $total]);
            return [$repStock->load(['rep', 'items.item']), $transfer->load(['fromBranch', 'toReferrer', 'items.item'])];
        }, 3);
    }
}
