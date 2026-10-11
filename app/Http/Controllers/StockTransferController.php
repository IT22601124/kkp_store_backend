<?php

namespace App\Http\Controllers;

use App\Models\AcceptedRequestItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Stock;
use App\Models\RepStock;
use App\Models\StockMovement;
use App\Models\Item;
use App\Models\User;
use App\Traits\FirebaseNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class StockTransferController extends Controller
{
    use FirebaseNotification;
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
            'items.*.batch_number' => 'nullable|string',
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

                    $lineDetails[] = [
                        'item' => $item,
                        'qty' => $qty,
                        'unitPrice' => $unitPrice,
                        'subtotal' => $subtotal,
                        'batchNumber' => $line['batch_number'] ?? null,
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
                    $batchNum = $line['batchNumber'] ?? null;

                    // A. Update Source Branch Warehouse Stock (matching specific batch if provided)
                    $stockQuery = Stock::where('branch_id', $validated['from_branch_id'])
                        ->whereNull('referrer_id')
                        ->where('item_id', $item->id);

                    if ($batchNum) {
                        $sourceStock = (clone $stockQuery)->where('batch_number', $batchNum)->first()
                            ?? (clone $stockQuery)->where('quantity', '>', 0)->first()
                            ?? $stockQuery->first();
                    } else {
                        $sourceStock = (clone $stockQuery)->where('quantity', '>', 0)->first()
                            ?? $stockQuery->first();
                    }

                    if (!$sourceStock) {
                        $sourceStock = Stock::create([
                            'branch_id' => $validated['from_branch_id'],
                            'referrer_id' => null,
                            'item_id' => $item->id,
                            'batch_number' => $batchNum,
                            'quantity' => 0,
                            'unit_cost' => $item->purchase_price,
                            'unit_price' => $item->selling_price,
                            'total_value' => 0.00
                        ]);
                    }

                    $effectiveBatch = $batchNum ?? $sourceStock->batch_number;

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
                        'notes' => "Stock issued to DSR Rep #{$validated['to_referrer_id']} (Batch: {$effectiveBatch})"
                    ]);

                    // B. Update Target DSR Rep Stock via AcceptedRequestItem
                    AcceptedRequestItem::create([
                        'rep_stock_id' => $repStock->id,
                        'item_id' => $item->id,
                        'quantity' => $qty,
                        'batch_number' => $effectiveBatch,
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
                        'batch_number' => $effectiveBatch,
                        'quantity' => $qty,
                        'unit_price' => $line['unitPrice'],
                        'subtotal' => $line['subtotal']
                    ]);
                }

                return $stockTransfer->load(['fromBranch', 'toReferrer', 'items.item']);
            });

            // Dispatch Firebase Push Notification to the recipient Rep
            try {
                $targetRep = $transfer->toReferrer ?? User::find($validated['to_referrer_id']);
                if (!$targetRep && !empty($validated['to_referrer_id'])) {
                    $targetRep = User::whereHas('dsrProfile', function ($q) use ($validated) {
                        $q->where('id', $validated['to_referrer_id']);
                    })->first();
                }

                if ($targetRep && !empty($targetRep->fcm_token)) {
                    $totalQty = $transfer->items ? $transfer->items->sum('quantity') : collect($validated['items'])->sum('quantity');
                    $itemCount = $transfer->items ? $transfer->items->count() : count($validated['items']);
                    $formattedValue = number_format((float) ($transfer->total_value ?? 0), 2);
                    $fromBranchName = $transfer->fromBranch->name ?? 'Main Warehouse';

                    $title = '📦 New Stock Issued';
                    $body = "You have received {$totalQty} unit(s) across {$itemCount} item(s) from {$fromBranchName} (Total Value: Rs. {$formattedValue}).";

                    $notifResult = $this->sendFirebaseNotification(
                        $targetRep->fcm_token,
                        $title,
                        $body,
                        [
                            'type' => 'stock_issued',
                            'transfer_id' => (string) $transfer->id,
                            'transfer_code' => (string) ($transfer->transfer_code ?? ''),
                            'total_value' => (string) ($transfer->total_value ?? 0),
                            'total_quantity' => (string) $totalQty,
                            'item_count' => (string) $itemCount,
                            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        ]
                    );

                    if (!empty($notifResult['success'])) {
                        Log::info("[StockTransferController] Firebase notification delivered to Rep #{$validated['to_referrer_id']} ({$targetRep->name}) for Transfer #{$transfer->id}");
                    } else {
                        Log::warning("[StockTransferController] Firebase notification could not be delivered to Rep #{$validated['to_referrer_id']}: " . ($notifResult['message'] ?? 'Unknown reason'));
                    }
                } else {
                    Log::info("[StockTransferController] Rep User #{$validated['to_referrer_id']} does not have an FCM device token. Notification skipped.");
                }
            } catch (Throwable $notifEx) {
                Log::warning('[StockTransferController] Firebase notification dispatch error: ' . $notifEx->getMessage());
            }

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
