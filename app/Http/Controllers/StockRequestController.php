<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\StockRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class StockRequestController extends Controller
{
    use ApiResponse;

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
}
