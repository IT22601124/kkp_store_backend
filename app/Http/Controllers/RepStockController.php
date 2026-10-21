<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\AcceptedRequestItem;
use App\Models\Item;
use App\Models\RepStock;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Traits\FirebaseNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class RepStockController extends Controller
{
    use ApiResponse, FirebaseNotification;

    /**
     * Display a listing of sales rep stocks.
     */
    public function index(Request $request)
    {
        try {
            $query = RepStock::with(['rep', 'items.item']);

            if ($request->user()->role === 'DSR_REP') {
                $query->where('rep_id', $request->user()->id);
            } elseif ($request->has('rep_id')) {
                $query->where('rep_id', $request->query('rep_id'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->query('status'));
            }

            $repStocks = $query->orderBy('updated_at', 'desc')->get();

            return $this->successResponse(
                $repStocks,
                'Rep stock balances retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve rep stocks',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Get stock balances for a specific rep.
     */
    public function getByRep(Request $request, $repId)
    {
        abort_if($request->user()->role === 'DSR_REP' && (int) $repId !== $request->user()->id, 404);
        try {
            $repStocks = RepStock::where('rep_id', $repId)
                ->with(['rep', 'items.item'])
                ->orderBy('updated_at', 'desc')
                ->get();

            if ($repStocks->isEmpty()) {
                return $this->successResponse(
                    [],
                    "Stock balances for rep #{$repId} retrieved successfully",
                    200
                );
            }

            $allItems = $repStocks->pluck('items')->flatten();

            $consolidatedItems = $allItems->groupBy('item_id')->map(function ($group) {
                $firstItem = $group->first();
                $totalQty = (int) $group->sum('quantity');
                $item = $firstItem->item;
                $unitPrice = $item ? (float) $item->selling_price : 0;
                $totalValue = round($totalQty * $unitPrice, 2);

                $batchNumbers = $group->pluck('batch_number')->filter()->unique()->values()->all();

                return [
                    'id' => $firstItem->id,
                    'rep_stock_id' => $firstItem->rep_stock_id,
                    'item_id' => (int) $firstItem->item_id,
                    'quantity' => $totalQty,
                    'total_value' => $totalValue,
                    'batch_number' => !empty($batchNumbers) ? implode(', ', $batchNumbers) : null,
                    'batch_numbers' => $batchNumbers,
                    'item' => $item,
                    'created_at' => $firstItem->created_at,
                    'updated_at' => $firstItem->updated_at,
                ];
            })->values();

            $latestRepStock = $repStocks->first();

            $consolidatedRepStock = [
                'id' => $latestRepStock->id,
                'rep_id' => (int) $repId,
                'total_value' => (float) $consolidatedItems->sum('total_value'),
                'status' => $latestRepStock->status,
                'created_at' => $latestRepStock->created_at,
                'updated_at' => $latestRepStock->updated_at,
                'rep' => $latestRepStock->rep,
                'items' => $consolidatedItems,
            ];

            return $this->successResponse(
                [$consolidatedRepStock],
                "Stock balances for rep #{$repId} retrieved successfully",
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve rep stock details',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Display details for a specific rep stock record.
     */
    public function show(Request $request, $id)
    {
        try {
            $repStock = RepStock::with(['rep', 'items.item'])
                ->when($request->user()->role === 'DSR_REP', fn ($q) => $q->where('rep_id', $request->user()->id))->find($id);

            if (!$repStock) {
                return $this->errorResponse('Rep stock record not found', 404);
            }

            return $this->successResponse(
                $repStock,
                'Rep stock details retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve rep stock record',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Issue stock for sales rep and fulfill stock request.
     */
    public function issueStockForRep(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['AGENT', 'SYSTEM_ADMIN']), 403);
        try {
            $validator = Validator::make($request->all(), [
                'request_id' => 'nullable|integer',
                'from_branch_id' => 'nullable|integer',
                'to_referrer_id' => 'nullable|integer',
                'rep_id' => 'nullable|integer',
                'items' => 'required|array|min:1',
                'items.*.item_id' => 'required|integer|exists:items,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.batch_number' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed', 422, $validator->errors()->first());
            }

            $request->validate(['request_id' => 'nullable|integer|exists:stock_requests,id', 'request_key' => 'nullable|uuid']);
            $requestId = $request->input('request_id');
            $stockRequest = $requestId ? StockRequest::findOrFail($requestId) : null;
            $rep_id = (int) ($stockRequest?->rep_id ?? $request->input('to_referrer_id') ?? $request->input('rep_id'));
            $branch_id = (int) ($stockRequest?->branch_id ?? $request->input('from_branch_id'));
            [$repStock] = app(\App\Services\StockIssuance::class)->issue($branch_id, $rep_id,
                $request->input('items'), $request->user(), $requestId ? (int) $requestId : null, $request->input('request_key'));

            // Dispatch Firebase Push Notification to the Sales Rep
            try {
                $targetRep = $repStock->rep ?? User::find($rep_id);
                if (!$targetRep && !empty($rep_id)) {
                    $targetRep = User::whereHas('dsrProfile', function ($q) use ($rep_id) {
                        $q->where('id', $rep_id);
                    })->first();
                }

                if ($targetRep && !empty($targetRep->fcm_token)) {
                    $totalQty = $repStock->items ? $repStock->items->sum('quantity') : collect($request->items)->sum('quantity');
                    $itemCount = $repStock->items ? $repStock->items->count() : count($request->items);
                    $formattedValue = number_format((float) ($repStock->total_value ?? 0), 2);

                    $title = '📦 New Stock Issued';
                    $body = "You have received {$totalQty} unit(s) across {$itemCount} item(s) (Total Value: Rs. {$formattedValue}).";

                    $notifResult = $this->sendFirebaseNotification(
                        $targetRep->fcm_token,
                        $title,
                        $body,
                        [
                            'type' => 'stock_issued',
                            'rep_stock_id' => (string) $repStock->id,
                            'request_id' => (string) ($requestId ?? ''),
                            'total_value' => (string) ($repStock->total_value ?? 0),
                            'total_quantity' => (string) $totalQty,
                            'item_count' => (string) $itemCount,
                            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        ]
                    );

                    if (!empty($notifResult['success'])) {
                        Log::info("[RepStockController] Firebase notification delivered to Rep #{$rep_id} ({$targetRep->name}) for RepStock #{$repStock->id}");
                    } else {
                        Log::warning("[RepStockController] Firebase notification could not be delivered to Rep #{$rep_id}: " . ($notifResult['message'] ?? 'Unknown reason'));
                    }
                } else {
                    Log::info("[RepStockController] Rep #{$rep_id} does not have an FCM device token registered. Notification skipped.");
                }
            } catch (Throwable $notifError) {
                Log::warning('[RepStockController] Firebase notification dispatch error during stock issuance: ' . $notifError->getMessage());
            }

            return $this->successResponse($repStock, 'Stock issued to rep successfully', 200);
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException $th) {
            throw $th;
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to issue stock to rep: ' . $th->getMessage(), 500);
        }
    }
}
