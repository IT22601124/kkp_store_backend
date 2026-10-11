<?php

namespace App\Http\Controllers;

use App\Events\StockRequestCreated;
use App\Http\Traits\ApiResponse;
use App\Models\StockRequest;
use App\Traits\FirebaseNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class StockRequestController extends Controller
{
    use ApiResponse, FirebaseNotification;

    /** List requests. Rep accounts only see their own requests. */
    public function index(Request $request)
    {
        $query = StockRequest::with(['rep', 'branch', 'items.item'])
            ->orderByDesc('id');

        if ($request->user()->role === 'DSR_REP') {
            $query->where('rep_id', $request->user()->id);
        } elseif ($request->filled('rep_id')) {
            $query->where('rep_id', $request->query('rep_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return $this->successResponse($query->get(), 'Stock requests retrieved successfully');
    }

    /** Create a stock request for the authenticated rep. */
    public function store(Request $request)
    {
        if ($request->user()->role !== 'DSR_REP') {
            return $this->errorResponse('Only sales reps can submit stock requests', 403);
        }

        $profile = $request->user()->dsrProfile;
        if (!$profile || !$profile->branch_id) {
            return $this->errorResponse('The sales rep does not have an assigned branch', 422);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'distinct', 'exists:items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $stockRequest = DB::transaction(function () use ($request, $profile, $validated) {
                $code = 'REQ-' . now()->format('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));

                $stockRequest = StockRequest::create([
                    'request_code' => $code,
                    'rep_id' => $request->user()->id,
                    'branch_id' => $profile->branch_id,
                    'status' => 'PENDING',
                    'notes' => $validated['notes'] ?? null,
                ]);

                foreach ($validated['items'] as $line) {
                    $stockRequest->items()->create([
                        'item_id' => $line['item_id'],
                        'requested_quantity' => $line['quantity'],
                    ]);
                }

                return $stockRequest->load(['rep', 'branch', 'items.item']);
            });

            // Broadcast real-time event to Web Dashboard via Pusher
            try {
                event(new StockRequestCreated($stockRequest));
                Log::info("[StockRequestController] StockRequestCreated event broadcasted via Pusher for Request #{$stockRequest->id} ({$stockRequest->request_code})");
            } catch (Throwable $broadcastError) {
                Log::warning('[StockRequestController] Real-time Pusher broadcast warning: ' . $broadcastError->getMessage());
            }

            // Broadcast real-time event to Web Dashboard via Firebase
            try {
                $repName = $stockRequest->rep->name ?? 'Sales Rep';
                $repCode = $stockRequest->rep->dsrProfile->rep_code ?? "REP-{$stockRequest->rep_id}";
                $itemsCount = $stockRequest->items ? $stockRequest->items->count() : 0;
                $branchName = $stockRequest->branch->name ?? 'Main Warehouse';

                $this->sendFirebaseTopicNotification(
                    'stock_requests',
                    '📦 New Stock Requisition',
                    "Rep {$repName} ({$repCode}) submitted a stock request for {$itemsCount} item(s).",
                    [
                        'type' => 'stock_requested',
                        'request_id' => (string) $stockRequest->id,
                        'request_code' => (string) $stockRequest->request_code,
                        'rep_id' => (string) $stockRequest->rep_id,
                        'rep_name' => (string) $repName,
                        'rep_code' => (string) $repCode,
                        'branch_name' => (string) $branchName,
                        'items_count' => (string) $itemsCount,
                        'created_at' => now()->toIso8601String(),
                    ]
                );
                Log::info("[StockRequestController] Firebase topic notification sent to 'stock_requests' for Request #{$stockRequest->id}");
            } catch (Throwable $notifError) {
                Log::warning('[StockRequestController] Firebase topic notification warning: ' . $notifError->getMessage());
            }

            return $this->successResponse($stockRequest, 'Stock request submitted successfully', 201);
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to submit stock request', 500, $th->getMessage());
        }
    }

    /** Show one request, scoped to the authenticated rep where applicable. */
    public function show(Request $request, StockRequest $stockRequest)
    {
        if ($request->user()->role === 'DSR_REP' && $stockRequest->rep_id !== $request->user()->id) {
            return $this->errorResponse('Stock request not found', 404);
        }

        return $this->successResponse(
            $stockRequest->load(['rep', 'branch', 'items.item']),
            'Stock request retrieved successfully'
        );
    }

    public function updateStatus(Request $request)
    {
        Log::info('requested data ', $request->all());

        $validated = $request->validate([
            'status' => ['required', 'in:PENDING,APPROVED,REJECTED,ACCEPTED'],
            'request_id' => ['required', 'integer', 'exists:stock_requests,id'],
        ]);

        try {
            $stockRequest = StockRequest::findOrFail($validated['request_id']);

            if ($request->user()->role === 'DSR_REP' && $stockRequest->rep_id !== $request->user()->id) {
                return $this->errorResponse('Stock request not found', 404);
            }

            $targetStatus = $validated['status'] === 'ACCEPTED' ? 'APPROVED' : $validated['status'];
            $stockRequest->update(['status' => $targetStatus]);
            $stockRequest->load(['rep', 'branch', 'items.item']);

            // Dispatch Firebase notification if approved
            if ($targetStatus === 'APPROVED' && $stockRequest->rep && !empty($stockRequest->rep->fcm_token)) {
                try {
                    $this->sendFirebaseNotification(
                        $stockRequest->rep->fcm_token,
                        '✅ Stock Request Approved',
                        "Your stock request #{$stockRequest->request_code} has been approved by the warehouse supervisor.",
                        [
                            'type' => 'stock_request_approved',
                            'request_id' => (string) $stockRequest->id,
                            'request_code' => (string) $stockRequest->request_code,
                            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        ]
                    );
                    Log::info("[StockRequestController] Firebase notification sent to Rep #{$stockRequest->rep_id} for approved request #{$stockRequest->id}");
                } catch (Throwable $notifEx) {
                    Log::warning('[StockRequestController] Firebase notification dispatch error: ' . $notifEx->getMessage());
                }
            }

            return $this->successResponse(
                $stockRequest,
                'Stock request status updated successfully'
            );
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to update stock request status', 500, $th->getMessage());
        }
    }
}
