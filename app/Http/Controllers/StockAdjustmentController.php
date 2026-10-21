<?php

namespace App\Http\Controllers;

use App\Models\AcceptedRequestItem;
use App\Models\Item;
use App\Models\RepStock;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Services\WarehouseLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockAdjustmentController extends Controller
{
    public function index()
    {
        return response()->json(['success' => true, 'data' => StockAdjustment::with(['branch', 'referrer', 'item'])->latest('id')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'target_type' => 'required|in:BRANCH_WAREHOUSE,DSR_REP', 'branch_id' => 'required|integer|exists:branches,id',
            'referrer_id' => 'required_if:target_type,DSR_REP|nullable|integer|exists:users,id',
            'item_id' => 'required|integer|exists:items,id',
            'adjustment_type' => 'required|in:DEDUCT_DAMAGE,RECONCILIATION_LOSS,RETURN_TO_WAREHOUSE,ADD_STOCK',
            'quantity_delta' => 'required|integer|not_in:0', 'reason' => 'required|string|max:2000',
            'batch_number' => 'nullable|string|max:100',
        ]);
        $delta = (int) $data['quantity_delta'];
        if (($data['adjustment_type'] === 'ADD_STOCK') !== ($delta > 0)) {
            throw ValidationException::withMessages(['quantity_delta' => 'Use a positive amount for added stock and a negative amount for deductions/returns.']);
        }
        if ($data['adjustment_type'] === 'RETURN_TO_WAREHOUSE' && $data['target_type'] !== 'DSR_REP') {
            throw ValidationException::withMessages(['target_type' => 'A return must identify the representative returning stock.']);
        }
        $adjustment = DB::transaction(function () use ($data, $request, $delta) {
            $item = Item::findOrFail($data['item_id']);
            $batch = $data['batch_number'] ?? null;
            $rep = null;
            if ($data['target_type'] === 'DSR_REP') {
                $rep = User::whereKey($data['referrer_id'])->lockForUpdate()->firstOrFail();
                if ($rep->role !== 'DSR_REP' || (int) $rep->dsrProfile?->branch_id !== (int) $data['branch_id']) {
                    throw ValidationException::withMessages(['referrer_id' => 'Select a representative in this branch.']);
                }
            }
            $record = StockAdjustment::create(array_diff_key($data, ['batch_number' => true]) + [
                'adjustment_code' => 'ADJ-'.strtoupper(bin2hex(random_bytes(8))),
                'adjusted_by' => $request->user()->name, 'adjustment_date' => now(),
            ]);
            if (!$rep) {
                app(WarehouseLedger::class)->change($data['branch_id'], $item, $delta, $batch,
                    'AUDIT_ADJUSTMENT', StockAdjustment::class, $record->id, $request->user()->name);
            } else {
                $stocks = RepStock::where('rep_id', $rep->id)->whereIn('status', ['ACTIVE', 'APPROVED', 'ACCEPTED'])->orderBy('id')->lockForUpdate()->get();
                $rows = AcceptedRequestItem::whereIn('rep_stock_id', $stocks->modelKeys())->where('item_id', $item->id)
                    ->when($batch !== null, fn ($q) => $q->where('batch_number', $batch))->orderBy('id')->lockForUpdate()->get();
                if ($delta < 0 && $rows->sum('quantity') < -$delta) {
                    throw ValidationException::withMessages(['quantity_delta' => 'Insufficient issued representative stock.']);
                }
                if ($delta > 0) {
                    $stock = $stocks->first() ?? RepStock::create(['rep_id' => $rep->id, 'status' => 'APPROVED', 'total_value' => 0]);
                    $row = $rows->first() ?? AcceptedRequestItem::create(['rep_stock_id' => $stock->id, 'item_id' => $item->id, 'quantity' => 0, 'batch_number' => $batch]);
                    $rows = collect([$row]);
                }
                $remaining = abs($delta);
                foreach ($rows as $row) {
                    $amount = $delta > 0 ? $remaining : min($remaining, max(0, $row->quantity));
                    if (!$amount) continue;
                    $before = $row->quantity;
                    $change = $delta > 0 ? $amount : -$amount;
                    $row->increment('quantity', $change);
                    DB::table('rep_stock_adjustment_lines')->insert(['stock_adjustment_id' => $record->id,
                        'accepted_request_item_id' => $row->id, 'quantity_before' => $before,
                        'quantity_change' => $change, 'quantity_after' => $before + $change]);
                    if ($data['adjustment_type'] === 'RETURN_TO_WAREHOUSE') {
                        app(WarehouseLedger::class)->change($data['branch_id'], $item, $amount, $row->batch_number,
                            'RETURN_TO_WAREHOUSE', StockAdjustment::class, $record->id, $request->user()->name);
                    }
                    $remaining -= $amount;
                    if (!$remaining) break;
                }
                foreach (RepStock::where('rep_id', $rep->id)->get() as $stock) {
                    $stock->update(['total_value' => $stock->items()->with('item')->get()->sum(fn ($line) => $line->quantity * (float) $line->item->selling_price)]);
                }
            }
            return $record->load(['branch', 'referrer', 'item']);
        }, 3);
        return response()->json(['success' => true, 'data' => $adjustment], 201);
    }
}
