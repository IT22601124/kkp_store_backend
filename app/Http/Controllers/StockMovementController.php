<?php

namespace App\Http\Controllers;

use App\Models\StockMovement;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $query = StockMovement::with(['branch', 'referrer', 'item', 'stock']);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }
        if ($request->has('referrer_id')) {
            $query->where('referrer_id', $request->query('referrer_id'));
        }
        if ($request->has('movement_type')) {
            $query->where('movement_type', $request->query('movement_type'));
        }

        $movements = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'message' => 'Stock movements audit trail retrieved',
            'data' => $movements
        ]);
    }
}
