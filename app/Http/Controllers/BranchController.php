<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class BranchController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of branches.
     */
    public function index()
    {
        try {
            $branches = Branch::latest()->get();

            return $this->successResponse(
                $branches,
                'Branches retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve branches',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Store a newly created branch.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'required|string|max:50|unique:branches,code',
                'address' => 'nullable|string',
            ]);

            $branch = Branch::create($validated);

            return $this->successResponse(
                $branch,
                'Branch created successfully',
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
                'Failed to create branch',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Display the specified branch.
     */
    public function show($id)
    {
        try {
            $branch = Branch::find($id);

            if (!$branch) {
                return $this->errorResponse(
                    'Branch not found',
                    404
                );
            }

            return $this->successResponse(
                $branch,
                'Branch details retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve branch',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Update the specified branch.
     */
    public function update(Request $request, $id)
    {
        try {
            $branch = Branch::find($id);

            if (!$branch) {
                return $this->errorResponse(
                    'Branch not found',
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
                    Rule::unique('branches', 'code')->ignore($branch->id),
                ],
                'address' => 'nullable|string',
            ]);

            $branch->update($validated);

            return $this->successResponse(
                $branch,
                'Branch updated successfully',
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
                'Failed to update branch',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Remove the specified branch.
     */
    public function destroy($id)
    {
        try {
            $branch = Branch::find($id);

            if (!$branch) {
                return $this->errorResponse(
                    'Branch not found',
                    404
                );
            }

            $branch->delete();

            return $this->successResponse(
                null,
                'Branch deleted successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to delete branch',
                500,
                $th->getMessage()
            );
        }
    }
}
