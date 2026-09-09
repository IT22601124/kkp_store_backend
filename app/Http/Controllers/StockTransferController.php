<?php

namespace App\Http\Controllers;

use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Stock;
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

                    // B. Update Target DSR Rep Stock
                    $targetStock = Stock::firstOrCreate(
                        [
                            'branch_id' => $validated['from_branch_id'],
                            'referrer_id' => $validated['to_referrer_id'],
                            'item_id' => $item->id,
                        ],
                        [
                            'quantity' => 0,
                            'unit_cost' => $item->purchase_price,
                            'unit_price' => $item->selling_price,
                            'total_value' => 0.00
                        ]
                    );

                    $targetQtyBefore = $targetStock->quantity;
                    $targetStock->quantity += $qty;
                    $targetStock->total_value = $targetStock->quantity * $targetStock->unit_price;
                    $targetStock->save();

                    StockMovement::create([
                        'stock_id' => $targetStock->id,
                        'item_id' => $item->id,
                        'branch_id' => $validated['from_branch_id'],
                        'referrer_id' => $validated['to_referrer_id'],
                        'movement_type' => 'TRANSFER_IN',
                        'quantity_before' => $targetQtyBefore,
                        'quantity_change' => $qty,
                        'quantity_after' => $targetStock->quantity,
                        'reference_type' => StockTransfer::class,
                        'reference_id' => $stockTransfer->id,
                        'performed_by' => auth()->user()->name ?? 'Branch Supervisor',
                        'notes' => "Stock received from Branch Warehouse #{$validated['from_branch_id']}"
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
}
