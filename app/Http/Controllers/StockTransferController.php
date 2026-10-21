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
            $request->validate(['request_key' => 'nullable|uuid']);
            [, $transfer] = app(\App\Services\StockIssuance::class)->issue((int) $validated['from_branch_id'],
                (int) $validated['to_referrer_id'], $validated['items'], $request->user(), null,
                $request->input('request_key'), $validated['notes'] ?? null);


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
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock transfer: ' . $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $transfer = StockTransfer::findOrFail($id);
        $validated = $request->validate([
            'notes' => 'nullable|string', 'status' => 'prohibited', 'items' => 'prohibited',
            'from_branch_id' => 'prohibited', 'to_referrer_id' => 'prohibited', 'total_value' => 'prohibited',
        ]);
        $transfer->update($validated);
        return response()->json(['success' => true, 'data' => $transfer->load(['fromBranch', 'toReferrer', 'items.item'])]);
    }

    public function destroy($id)
    {
        StockTransfer::findOrFail($id);
        throw \Illuminate\Validation\ValidationException::withMessages([
            'stock_transfer' => 'Issued stock transfers are permanent. Record a stock return or adjustment instead.',
        ]);
    }
}
