<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\GoodsReceivedNote;
use App\Models\Item;
use App\Services\WarehouseLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrnController extends Controller
{
    public function index()
    {
        return response()->json(['success' => true, 'data' => GoodsReceivedNote::with(['branch', 'items.item'])->latest('id')->get()]);
    }

    public function show(int $id)
    {
        return response()->json(['success' => true, 'data' => GoodsReceivedNote::with(['branch', 'items.item'])->findOrFail($id)]);
    }

    private function input(Request $request, bool $update = false): array
    {
        return $request->validate([
            'branch_id' => ($update ? 'sometimes' : 'required').'|integer|exists:branches,id',
            'supplier_invoice_no' => ($update ? 'sometimes' : 'required').'|string|max:255',
            'received_by' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:5000',
            'status' => 'sometimes|in:VERIFIED,PENDING',
            'items' => ($update ? 'sometimes' : 'required').'|array|min:1',
            'items.*.item_id' => 'required|integer|exists:items,id',
            'items.*.quantity_received' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0|decimal:0,2',
            'items.*.batch_number' => 'nullable|string|max:100',
        ]);
    }

    private function lines(array $data): array
    {
        return array_map(fn ($line) => [
            'item_id' => $line['item_id'], 'quantity_received' => $line['quantity_received'],
            'unit_price' => $line['unit_price'], 'total_amount' => round($line['quantity_received'] * $line['unit_price'], 2),
            'batch_number' => $line['batch_number'] ?? 'BATCH-'.strtoupper(bin2hex(random_bytes(6))),
        ], $data);
    }

    private function apply(GoodsReceivedNote $grn, array $oldLines, array $newLines, int $oldBranch, string $actor): void
    {
        $deltas = [];
        foreach ([[$oldLines, -1, $oldBranch], [$newLines, 1, $grn->branch_id]] as [$lines, $sign, $branch]) {
            foreach ($lines as $line) {
                $key = json_encode([$branch, $line['item_id'], $line['batch_number']]);
                $deltas[$key] ??= ['branch' => $branch, 'item' => $line['item_id'], 'batch' => $line['batch_number'], 'delta' => 0, 'cost' => $line['unit_price']];
                $deltas[$key]['delta'] += $sign * $line['quantity_received'];
                if ($sign > 0) $deltas[$key]['cost'] = $line['unit_price'];
            }
        }
        // Deterministic branch order is shared by all warehouse mutations.
        Branch::whereIn('id', [$oldBranch, $grn->branch_id])->orderBy('id')->lockForUpdate()->get();
        foreach ($deltas as $change) {
            if (!$change['delta']) continue;
            app(WarehouseLedger::class)->change($change['branch'], Item::findOrFail($change['item']), $change['delta'],
                $change['batch'], $oldLines ? 'AUDIT_ADJUSTMENT' : 'GRN_RECEIPT', GoodsReceivedNote::class, $grn->id, $actor, $change['cost']);
        }
    }

    public function store(Request $request)
    {
        $data = $this->input($request);
        $grn = DB::transaction(function () use ($data, $request) {
            $lines = $this->lines($data['items']);
            unset($data['items']);
            // A GRN represents received stock; pending requests are not receipts.
            if (($data['status'] ?? 'VERIFIED') !== 'VERIFIED') {
                throw ValidationException::withMessages(['status' => 'Confirm receipt before recording warehouse stock.']);
            }
            $data['received_by'] = $data['received_by'] ?? $request->user()->name;
            $grn = GoodsReceivedNote::create($data + ['grn_number' => 'GRN-'.strtoupper(bin2hex(random_bytes(8))),
                'received_date' => now(), 'received_by' => $request->user()->name, 'status' => 'VERIFIED',
                'total_value' => array_sum(array_column($lines, 'total_amount'))]);
            $this->apply($grn, [], $lines, $grn->branch_id, $request->user()->name);
            $grn->items()->createMany($lines);
            return $grn->load(['branch', 'items.item']);
        }, 3);
        return response()->json(['success' => true, 'data' => $grn], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $this->input($request, true);
        $grn = DB::transaction(function () use ($data, $request, $id) {
            $grn = GoodsReceivedNote::whereKey($id)->lockForUpdate()->firstOrFail();
            if (isset($data['status']) && $data['status'] !== 'VERIFIED') {
                throw ValidationException::withMessages(['status' => 'Received stock cannot be changed back to pending.']);
            }
            $oldBranch = $grn->branch_id;
            $old = $grn->items->toArray();
            $new = isset($data['items']) ? $this->lines($data['items']) : $old;
            unset($data['items']);
            $grn->fill($data);
            $this->apply($grn, $old, $new, $oldBranch, $request->user()->name);
            if ($request->has('items')) {
                $grn->items()->delete();
                $grn->items()->createMany($new);
            }
            $grn->total_value = array_sum(array_column($new, 'total_amount'));
            $grn->save();
            return $grn->fresh(['branch', 'items.item']);
        }, 3);
        return response()->json(['success' => true, 'data' => $grn]);
    }

    public function destroy(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $grn = GoodsReceivedNote::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->apply($grn, $grn->items->toArray(), [], $grn->branch_id, $request->user()->name);
            $grn->delete();
        }, 3);
        return response()->json(['success' => true]);
    }
}
