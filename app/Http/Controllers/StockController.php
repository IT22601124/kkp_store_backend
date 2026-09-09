<?php

namespace App\Http\Controllers;

use App\Models\Stock;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function index(Request $request)
    {
        $query = Stock::with(['branch', 'referrer', 'item']);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }

        if ($request->has('referrer_id')) {
            $query->where('referrer_id', $request->query('referrer_id'));
        }

        if ($request->has('item_id')) {
            $query->where('item_id', $request->query('item_id'));
        }

        $stocks = $query->orderBy('updated_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'message' => 'Stock balances retrieved successfully',
            'data' => $stocks
        ]);
    }

    public function show($id)
    {
        $stock = Stock::with(['branch', 'referrer', 'item', 'movements'])->find($id);

        if (!$stock) {
            return response()->json([
                'success' => false,
                'message' => 'Stock record not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $stock
        ]);
    }
}
