<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Item;
use Illuminate\Http\Request;

class MobileItemController extends Controller
{
    use ApiResponse;

    /** List active items with warehouse stock from the authenticated rep's branch. */
    public function index(Request $request)
    {
        $rep = $request->user();
        if ($rep->role !== 'DSR_REP') {
            return $this->errorResponse('Only sales reps can access mobile items', 403);
        }

        $rep->loadMissing('dsrProfile.branch');
        $branch = $rep->dsrProfile?->branch;
        if (!$branch) {
            return $this->errorResponse('The sales rep does not have an assigned branch', 422);
        }
        $branchId = $branch->id;

        $query = Item::with([
            'category',
            'stocks' => fn ($stocks) => $stocks
                ->where('branch_id', $branchId)
                ->whereNull('referrer_id'),
        ])
            ->where('status', 'ACTIVE')
            ->orderBy('name');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($items) use ($search) {
                $items->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $data = $query->get()->map(function (Item $item) use ($branchId) {
            $quantity = $item->stocks->sum('quantity');
            $reservedQuantity = $item->stocks->sum('reserved_quantity');

            return [
                'id' => (int) $item->id,
                'name' => $item->name,
                'code' => $item->code,
                'purchase_price' => (float) $item->purchase_price,
                'selling_price' => (float) $item->selling_price,
                'market_price' => (float) $item->market_price,
                'status' => $item->status,
                'stock_quantity' => (int) $quantity,
                'reserved_quantity' => (int) $reservedQuantity,
                'branch_id' => (int) $branchId,
                'stock_status' => $quantity > 0 ? 'IN_STOCK' : 'OUT_OF_STOCK',
            ];
        });

        return $this->successResponse($data, 'Mobile items retrieved successfully');
    }

    /** Show one active item with warehouse stock from the authenticated rep's branch. */
    public function show(Request $request, int $id)
    {
        $rep = $request->user();
        if ($rep->role !== 'DSR_REP') {
            return $this->errorResponse('Only sales reps can access mobile items', 403);
        }

        $rep->loadMissing('dsrProfile.branch');
        $branch = $rep->dsrProfile?->branch;
        if (!$branch) {
            return $this->errorResponse('The sales rep does not have an assigned branch', 422);
        }
        $branchId = $branch->id;

        $item = Item::with([
            'category',
            'stocks' => fn ($stocks) => $stocks
                ->where('branch_id', $branchId)
                ->whereNull('referrer_id'),
        ])
            ->where('status', 'ACTIVE')
            ->find($id);

        if (!$item) {
            return $this->errorResponse('Item not found', 404);
        }

        $quantity = $item->stocks->sum('quantity');
        $reservedQuantity = $item->stocks->sum('reserved_quantity');

        return $this->successResponse([
            'id' => (int) $item->id,
            'name' => $item->name,
            'code' => $item->code,
            'category_id' => (int) $item->category_id,
            'category' => $item->category,
            'purchase_price' => (float) $item->purchase_price,
            'selling_price' => (float) $item->selling_price,
            'market_price' => (float) $item->market_price,
            'status' => $item->status,
            'stock_quantity' => (int) $quantity,
            'reserved_quantity' => (int) $reservedQuantity,
            'branch_id' => (int) $branchId,
            'stock_status' => $quantity > 0 ? 'IN_STOCK' : 'OUT_OF_STOCK',
        ], 'Mobile item retrieved successfully');
    }
}
