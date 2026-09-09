<?php

namespace App\Http\Controllers;

use App\Models\StockAdjustment;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockAdjustmentController extends Controller
{
    public function index()
    {
        $adjustments = StockAdjustment::with(['branch', 'referrer', 'item'])
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Stock adjustments retrieved successfully',
            'data' => $adjustments
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'target_type' => 'required|in:BRANCH_WAREHOUSE,DSR_REP',
            'branch_id' => 'required|exists:branches,id',
            'referrer_id' => 'nullable|exists:users,id',
            'item_id' => 'required|exists:items,id',
            'adjustment_type' => 'required|in:DEDUCT_DAMAGE,RECONCILIATION_LOSS,RETURN_TO_WAREHOUSE,ADD_STOCK',
            'quantity_delta' => 'required|integer',
            'reason' => 'required|string',
            'adjusted_by' => 'nullable|string'
        ]);

        try {
            $adjustment = DB::transaction(function () use ($validated) {
                $item = Item::findOrFail($validated['item_id']);
                $referrerId = $validated['target_type'] === 'DSR_REP' ? $validated['referrer_id'] : null;

                // 1. Find or create target Stock record
                $stock = Stock::firstOrCreate(
                    [
                        'branch_id' => $validated['branch_id'],
                        'referrer_id' => $referrerId,
                        'item_id' => $item->id
                    ],
                    [
                        'quantity' => 0,
                        'unit_cost' => $item->purchase_price,
                        'unit_price' => $item->selling_price,
                        'total_value' => 0.00
                    ]
                );

                $qtyBefore = $stock->quantity;
                $delta = (int) $validated['quantity_delta'];
                $stock->quantity += $delta;
                $stock->total_value = $stock->quantity * $stock->unit_price;
                $stock->save();

                // 2. Create StockAdjustment record
                $adjCode = 'ADJ-' . date('Y') . '-' . str_pad(StockAdjustment::count() + 1, 4, '0', STR_PAD_LEFT);
                $stockAdjustment = StockAdjustment::create([
                    'adjustment_code' => $adjCode,
                    'target_type' => $validated['target_type'],
                    'branch_id' => $validated['branch_id'],
                    'referrer_id' => $referrerId,
                    'item_id' => $item->id,
                    'adjustment_type' => $validated['adjustment_type'],
                    'quantity_delta' => $delta,
                    'reason' => $validated['reason'],
                    'adjusted_by' => $validated['adjusted_by'] ?? auth()->user()->name ?? 'Branch Supervisor',
                    'adjustment_date' => now()
                ]);

                // 3. Log Stock Movement
                $mType = 'AUDIT_ADJUSTMENT';
                if ($validated['adjustment_type'] === 'DEDUCT_DAMAGE') $mType = 'DAMAGE_DEDUCTION';
                if ($validated['adjustment_type'] === 'RETURN_TO_WAREHOUSE') $mType = 'RETURN_TO_WAREHOUSE';
                if ($validated['adjustment_type'] === 'ADD_STOCK') $mType = 'GRN_RECEIPT';

                StockMovement::create([
                    'stock_id' => $stock->id,
                    'item_id' => $item->id,
                    'branch_id' => $validated['branch_id'],
                    'referrer_id' => $referrerId,
                    'movement_type' => $mType,
                    'quantity_before' => $qtyBefore,
                    'quantity_change' => $delta,
                    'quantity_after' => $stock->quantity,
                    'reference_type' => StockAdjustment::class,
                    'reference_id' => $stockAdjustment->id,
                    'performed_by' => $validated['adjusted_by'] ?? 'Branch Officer',
                    'notes' => $validated['reason']
                ]);

                return $stockAdjustment->load(['branch', 'referrer', 'item']);
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock adjustment recorded successfully',
                'data' => $adjustment
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record stock adjustment: ' . $e->getMessage()
            ], 500);
        }
    }
}
