<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CategoryController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of categories.
     */
    public function index()
    {
        try {
            $categories = Category::withCount('items')->latest()->get();

            return $this->successResponse(
                $categories,
                'Categories retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve categories',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Store a newly created category.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'required|string|max:50|unique:categories,code',
                'badge_color' => 'nullable|string|max:100',
                'status' => 'nullable|string|max:50',
            ]);

            $category = Category::create($validated);

            return $this->successResponse(
                $category,
                'Category created successfully',
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
                'Failed to create category',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Display the specified category.
     */
    public function show($id)
    {
        try {
            $category = Category::with('items')->find($id);

            if (!$category) {
                return $this->errorResponse(
                    'Category not found',
                    404
                );
            }

            return $this->successResponse(
                $category,
                'Category details retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve category',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Update the specified category.
     */
    public function update(Request $request, $id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return $this->errorResponse(
                    'Category not found',
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
                    Rule::unique('categories', 'code')->ignore($category->id),
                ],
                'badge_color' => 'nullable|string|max:100',
                'status' => 'nullable|string|max:50',
            ]);

            $category->update($validated);

            return $this->successResponse(
                $category,
                'Category updated successfully',
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
                'Failed to update category',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Remove the specified category.
     */
    public function destroy($id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return $this->errorResponse(
                    'Category not found',
                    404
                );
            }

            $category->delete();

            return $this->successResponse(
                null,
                'Category deleted successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to delete category',
                500,
                $th->getMessage()
            );
        }
    }
}
