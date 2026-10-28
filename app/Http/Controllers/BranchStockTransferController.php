<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchStockTransfer;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchStockTransferController extends Controller
{
    public function index()
    {
        return response()->json(['success' => true, 'data' => BranchStockTransfer::with(['fromBranch', 'toBranch', 'creator:id,name'])->latest('id')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'request_key' => 'required|uuid',
            'from_branch_id' => 'required|integer|exists:branches,id',
            'to_branch_id' => 'required|integer|different:from_branch_id|exists:branches,id',
            'notes' => 'nullable|string|max:5000',
            'items' => 'required|array|min:1|max:100',
            'items.*.stock_id' => 'required|integer|distinct|exists:stocks,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);
        $transfer = DB::transaction(function () use ($data, $request) {
            // Every warehouse operation locks branches before stock; use the same order in both directions.
            Branch::whereIn('id', [$data['from_branch_id'], $data['to_branch_id']])->orderBy('id')->lockForUpdate()->get();
            $hash = hash('sha256', json_encode([$request->user()->id, $data], JSON_THROW_ON_ERROR));
            $existing = BranchStockTransfer::where('request_key', $data['request_key'])->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    throw ValidationException::withMessages(['request_key' => 'This submission was already used for a different transfer. Check the transfer history before trying again.']);
                }
                return $existing->load(['fromBranch', 'toBranch', 'creator:id,name']);
            }

            $stocks = Stock::with('item')->where('branch_id', $data['from_branch_id'])->whereNull('referrer_id')
                ->whereIn('id', array_column($data['items'], 'stock_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            $totalCents = 0;
            foreach ($data['items'] as $index => $line) {
                $source = $stocks->get($line['stock_id']);
                if (!$source) {
                    throw ValidationException::withMessages(["items.$index.stock_id" => 'Select stock belonging to the source branch warehouse.']);
                }
                if ($line['quantity'] > max(0, $source->quantity - $source->reserved_quantity)) {
                    throw ValidationException::withMessages(["items.$index.quantity" => 'Not enough available stock for '.$source->item->name.'. Reduce the quantity or choose another batch.']);
                }
                $totalCents += (int) round($source->unit_price * $line['quantity'] * 100);
                $lines[] = ['stock_id' => $source->id, 'item_id' => $source->item_id, 'item_name' => $source->item->name,
                    'batch_number' => $source->batch_number, 'quantity' => $line['quantity'],
                    'unit_cost' => $source->unit_cost, 'unit_price' => $source->unit_price];
            }
            $transfer = BranchStockTransfer::create([
                'transfer_code' => 'BTR-'.strtoupper(bin2hex(random_bytes(8))), 'request_key' => $data['request_key'], 'request_hash' => $hash,
                'from_branch_id' => $data['from_branch_id'], 'to_branch_id' => $data['to_branch_id'],
                'created_by' => $request->user()->id, 'items' => $lines, 'total_value' => number_format($totalCents / 100, 2, '.', ''), 'notes' => $data['notes'] ?? null,
            ]);
            foreach ($lines as $line) {
                $source = $stocks[$line['stock_id']];
                // Keep batches with different prices/costs separate, preserving the value carried by the source stock.
                $destination = Stock::where('branch_id', $data['to_branch_id'])->whereNull('referrer_id')
                    ->where('item_id', $source->item_id)->where('batch_number', $source->batch_number)
                    ->where('unit_cost', $source->unit_cost)->where('unit_price', $source->unit_price)->orderBy('id')->lockForUpdate()->first();
                $destination ??= Stock::create(['branch_id' => $data['to_branch_id'], 'referrer_id' => null,
                    'item_id' => $source->item_id, 'batch_number' => $source->batch_number, 'quantity' => 0,
                    'unit_cost' => $source->unit_cost, 'unit_price' => $source->unit_price, 'total_value' => 0]);
                foreach ([[$source, -$line['quantity'], 'TRANSFER_OUT'], [$destination, $line['quantity'], 'TRANSFER_IN']] as [$stock, $delta, $type]) {
                    $before = $stock->quantity;
                    $stock->quantity += $delta;
                    $stock->status = $stock->quantity <= 0 ? 'OUT_OF_STOCK' : ($stock->quantity <= 10 ? 'LOW_STOCK' : 'IN_STOCK');
                    $stock->total_value = round($stock->quantity * $stock->unit_price, 2);
                    $stock->save();
                    StockMovement::create(['stock_id' => $stock->id, 'item_id' => $stock->item_id, 'branch_id' => $stock->branch_id,
                        'movement_type' => $type, 'quantity_before' => $before, 'quantity_change' => $delta,
                        'quantity_after' => $stock->quantity, 'reference_type' => BranchStockTransfer::class,
                        'reference_id' => $transfer->id, 'performed_by' => $request->user()->name]);
                }
            }
            foreach ([$data['from_branch_id'], $data['to_branch_id']] as $branchId) {
                Branch::whereKey($branchId)->update(['total_warehouse_stock_value' => Stock::where('branch_id', $branchId)->whereNull('referrer_id')->sum('total_value')]);
            }
            return $transfer->load(['fromBranch', 'toBranch', 'creator:id,name']);
        }, 3);
        return response()->json(['success' => true, 'data' => $transfer], 201);
    }
}
