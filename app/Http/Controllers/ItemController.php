<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ItemController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of items.
     */
    public function index()
    {
        try {
            $items = Item::with('category')->latest()->get();

            return $this->successResponse(
                $items,
                'Items retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve items',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Store a newly created item.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'required|string|max:50|unique:items,code',
                'category_id' => 'required|exists:categories,id',
                'purchase_price' => 'required|numeric|min:0',
                'selling_price' => 'required|numeric|min:0',
                'market_price' => 'nullable|numeric|min:0',
                'status' => 'nullable|string|max:50',
            ]);

            $item = Item::create($validated);
            $item->load('category');

            return $this->successResponse(
                $item,
                'Item created successfully',
                201
            );
        } catch (ValidationException $ve) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $ve->errors()
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to create item',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Display the specified item.
     */
    public function show($id)
    {
        try {
            $item = Item::with('category')->find($id);

            if (!$item) {
                return $this->errorResponse(
                    'Item not found',
                    404
                );
            }

            return $this->successResponse(
                $item,
                'Item details retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve item',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Update the specified item.
     */
    public function update(Request $request, $id)
    {
        try {
            $item = Item::find($id);

            if (!$item) {
                return $this->errorResponse(
                    'Item not found',
                    404
                );
            }

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'code' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('items', 'code')->ignore($item->id),
                ],
                'category_id' => 'sometimes|required|exists:categories,id',
                'purchase_price' => 'sometimes|required|numeric|min:0',
                'selling_price' => 'sometimes|required|numeric|min:0',
                'market_price' => 'nullable|numeric|min:0',
                'status' => 'nullable|string|max:50',
            ]);

            $item->update($validated);
            $item->load('category');

            return $this->successResponse(
                $item,
                'Item updated successfully',
                200
            );
        } catch (ValidationException $ve) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $ve->errors()
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to update item',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Remove the specified item.
     */
    public function destroy($id)
    {
        try {
            $item = Item::find($id);

            if (!$item) {
                return $this->errorResponse(
                    'Item not found',
                    404
                );
            }

            $item->delete();

            return $this->successResponse(
                null,
                'Item deleted successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to delete item',
                500,
                $th->getMessage()
            );
        }
    }
}
