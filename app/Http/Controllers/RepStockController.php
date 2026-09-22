<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\RepStock;
use Illuminate\Http\Request;
use Throwable;

class RepStockController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of sales rep stocks.
     */
    public function index(Request $request)
    {
        try {
            $query = RepStock::with(['rep', 'branch', 'item']);

            if ($request->has('rep_id')) {
                $query->where('rep_id', $request->query('rep_id'));
            }

            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->query('branch_id'));
            }

            if ($request->has('item_id')) {
                $query->where('item_id', $request->query('item_id'));
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
    public function getByRep($repId)
    {
        try {
            $repStocks = RepStock::where('rep_id', $repId)
                ->with(['branch', 'item'])
                ->orderBy('updated_at', 'desc')
                ->get();

            return $this->successResponse(
                $repStocks,
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
    public function show($id)
    {
        try {
            $repStock = RepStock::with(['rep', 'branch', 'item'])->find($id);

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
}
