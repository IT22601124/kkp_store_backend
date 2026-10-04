<?php

namespace App\Http\Controllers;

use App\Models\AcceptedRequestItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Stock;
use App\Models\RepStock;
use App\Models\StockMovement;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockTransferController extends Controller
{
    public function index()
    {
        $transfers = StockTransfer::with(['fromBranch', 'toReferrer', 'items.item'])
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Stock transfers retrieved successfully',
            'data' => $transfers
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_branch_id' => 'required|exists:branches,id',
            'to_referrer_id' => 'required|exists:users,id',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string'
        ]);

        try {
            $transfer = DB::transaction(function () use ($validated) {
                $totalValue = 0;
                $lineDetails = [];

                // 1. Calculate subtotals & check source warehouse stock availability
                foreach ($validated['items'] as $line) {
                    $item = Item::findOrFail($line['item_id']);
                    $qty = (int) $line['quantity'];
                    $unitPrice = (float) $item->selling_price;
                    $subtotal = $qty * $unitPrice;
                    $totalValue += $subtotal;

                    // Check source warehouse stock
                    $sourceStock = Stock::where('branch_id', $validated['from_branch_id'])
                        ->whereNull('referrer_id')
                        ->where('item_id', $item->id)
                        ->first();

                    $lineDetails[] = [
                        'item' => $item,
                        'qty' => $qty,
                        'unitPrice' => $unitPrice,
                        'subtotal' => $subtotal,
                        'sourceStock' => $sourceStock
                    ];
                }

                // 2. Create StockTransfer Header
                $transferCode = 'TRF-' . str_pad(StockTransfer::count() + 1, 5, '0', STR_PAD_LEFT);
                $stockTransfer = StockTransfer::create([
                    'transfer_code' => $transferCode,
                    'from_branch_id' => $validated['from_branch_id'],
                    'to_referrer_id' => $validated['to_referrer_id'],
                    'transfer_date' => now(),
                    'status' => 'ISSUED',
                    'total_value' => $totalValue,
                    'notes' => $validated['notes'] ?? null
                ]);

                // Create RepStock record
                $repStock = RepStock::create([
                    'rep_id' => $validated['to_referrer_id'],
                    'total_value' => $totalValue,
                    'status' => 'ACCEPTED',
                ]);

                // 3. Process each item: update source & target stocks + movement audit logs
                foreach ($lineDetails as $line) {
                    $item = $line['item'];
                    $qty = $line['qty'];

                    // A. Update Source Branch Warehouse Stock
                    $sourceStock = Stock::firstOrCreate(
                        [
                            'branch_id' => $validated['from_branch_id'],
                            'referrer_id' => null,
                            'item_id' => $item->id,
                        ],
                        [
                            'quantity' => 0,
                            'unit_cost' => $item->purchase_price,
                            'unit_price' => $item->selling_price,
                            'total_value' => 0.00
                        ]
                    );

                    $sourceQtyBefore = $sourceStock->quantity;
                    $sourceStock->quantity -= $qty;
                    $sourceStock->total_value = $sourceStock->quantity * $sourceStock->unit_price;
                    $sourceStock->save();

                    StockMovement::create([
                        'stock_id' => $sourceStock->id,
                        'item_id' => $item->id,
                        'branch_id' => $validated['from_branch_id'],
                        'referrer_id' => null,
                        'movement_type' => 'TRANSFER_OUT',
                        'quantity_before' => $sourceQtyBefore,
                        'quantity_change' => -$qty,
                        'quantity_after' => $sourceStock->quantity,
                        'reference_type' => StockTransfer::class,
                        'reference_id' => $stockTransfer->id,
                        'performed_by' => auth()->user()->name ?? 'Branch Supervisor',
                        'notes' => "Stock issued to DSR Rep #{$validated['to_referrer_id']}"
                    ]);

                    // B. Update Target DSR Rep Stock via AcceptedRequestItem
                    AcceptedRequestItem::create([
                        'rep_stock_id' => $repStock->id,
                        'item_id' => $item->id,
                        'quantity' => $qty,
                        'batch_number' => null,
                    ]);

                    StockMovement::create([
                        'stock_id' => $sourceStock->id,
                        'item_id' => $item->id,
                        'branch_id' => $validated['from_branch_id'],
                        'referrer_id' => $validated['to_referrer_id'],
                        'movement_type' => 'TRANSFER_IN',
                        'quantity_before' => 0,
                        'quantity_change' => $qty,
                        'quantity_after' => $qty,
                        'reference_type' => StockTransfer::class,
                        'reference_id' => $stockTransfer->id,
                        'performed_by' => auth()->user()->name ?? 'Branch Supervisor',
                        'notes' => "Stock received by Rep from Branch Warehouse #{$validated['from_branch_id']}"
                    ]);

                    // C. Create Transfer Line Item Record
                    StockTransferItem::create([
                        'stock_transfer_id' => $stockTransfer->id,
                        'item_id' => $item->id,
                        'quantity' => $qty,
                        'unit_price' => $line['unitPrice'],
                        'subtotal' => $line['subtotal']
                    ]);
                }

                return $stockTransfer->load(['fromBranch', 'toReferrer', 'items.item']);
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock transfer transmitted successfully',
                'data' => $transfer
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process stock transfer: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $transfer = StockTransfer::with(['fromBranch', 'toReferrer', 'items.item'])->find($id);

            if (!$transfer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock transfer not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Stock transfer retrieved successfully',
                'data' => $transfer
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock transfer: ' . $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $transfer = StockTransfer::find($id);

            if (!$transfer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock transfer not found'
                ], 404);
            }

            $validated = $request->validate([
                'status' => 'nullable|string',
                'notes' => 'nullable|string',
                'from_branch_id' => 'nullable|exists:branches,id',
                'to_referrer_id' => 'nullable|exists:users,id',
                'total_value' => 'nullable|numeric'
            ]);

            $transfer->update(array_filter($validated, function ($value) {
                return $value !== null;
            }));

            $transfer->load(['fromBranch', 'toReferrer', 'items.item']);

            return response()->json([
                'success' => true,
                'message' => 'Stock transfer updated successfully',
                'data' => $transfer
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock transfer: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $transfer = StockTransfer::find($id);

            if (!$transfer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stock transfer not found'
                ], 404);
            }

            $transfer->delete();

            return response()->json([
                'success' => true,
                'message' => 'Stock transfer deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete stock transfer: ' . $e->getMessage()
            ], 500);
        }
    }
}
