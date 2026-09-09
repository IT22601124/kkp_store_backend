<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceivedNote;
use App\Models\GrnItem;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Item;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GrnController extends Controller
{
    public function index()
    {
        $grns = GoodsReceivedNote::with(['branch', 'items.item'])
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'GRNs retrieved successfully',
            'data' => $grns
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'supplier_invoice_no' => 'required|string',
            'received_by' => 'required|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.quantity_received' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'notes' => 'nullable|string'
        ]);

        try {
            $grn = DB::transaction(function () use ($validated) {
                $totalValue = 0;
                $lineDetails = [];

                foreach ($validated['items'] as $line) {
                    $item = Item::findOrFail($line['item_id']);
                    $qty = (int) $line['quantity_received'];
                    $unitPrice = (float) $line['unit_price'];
                    $subtotal = $qty * $unitPrice;
                    $totalValue += $subtotal;

                    $lineDetails[] = [
                        'item' => $item,
                        'qty' => $qty,
                        'unitPrice' => $unitPrice,
                        'subtotal' => $subtotal
                    ];
                }

                $grnNumber = 'GRN-' . date('Y') . '-' . str_pad(GoodsReceivedNote::count() + 1, 4, '0', STR_PAD_LEFT);
                $grnRecord = GoodsReceivedNote::create([
                    'grn_number' => $grnNumber,
                    'branch_id' => $validated['branch_id'],
                    'supplier_invoice_no' => $validated['supplier_invoice_no'],
                    'received_date' => now(),
                    'received_by' => $validated['received_by'],
                    'total_value' => $totalValue,
                    'status' => 'VERIFIED',
                    'notes' => $validated['notes'] ?? null
                ]);

                foreach ($lineDetails as $line) {
                    $item = $line['item'];
                    $qty = $line['qty'];

                    // Update Branch Warehouse Stock
                    $stock = Stock::firstOrCreate(
                        [
                            'branch_id' => $validated['branch_id'],
                            'referrer_id' => null,
                            'item_id' => $item->id
                        ],
                        [
                            'quantity' => 0,
                            'unit_cost' => $line['unitPrice'],
                            'unit_price' => $item->selling_price,
                            'total_value' => 0.00
                        ]
                    );

                    $qtyBefore = $stock->quantity;
                    $stock->quantity += $qty;
                    $stock->unit_cost = $line['unitPrice'];
                    $stock->total_value = $stock->quantity * $stock->unit_price;
                    $stock->save();

                    // Movement audit log
                    StockMovement::create([
                        'stock_id' => $stock->id,
                        'item_id' => $item->id,
                        'branch_id' => $validated['branch_id'],
                        'referrer_id' => null,
                        'movement_type' => 'GRN_RECEIPT',
                        'quantity_before' => $qtyBefore,
                        'quantity_change' => $qty,
                        'quantity_after' => $stock->quantity,
                        'reference_type' => GoodsReceivedNote::class,
                        'reference_id' => $grnRecord->id,
                        'performed_by' => $validated['received_by'],
                        'notes' => "Supplier GRN intake invoice #{$validated['supplier_invoice_no']}"
                    ]);

                    GrnItem::create([
                        'goods_received_note_id' => $grnRecord->id,
                        'item_id' => $item->id,
                        'quantity_received' => $qty,
                        'unit_price' => $line['unitPrice'],
                        'total_amount' => $line['subtotal']
                    ]);
                }

                // Increment Branch warehouse stock total value
                $branch = Branch::find($validated['branch_id']);
                if ($branch) {
                    $branch->increment('total_warehouse_stock_value', $totalValue);
                }

                return $grnRecord->load(['branch', 'items.item']);
            });

            return response()->json([
                'success' => true,
                'message' => 'GRN stock intake verified & updated',
                'data' => $grn
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record GRN: ' . $e->getMessage()
            ], 500);
        }
    }
}
