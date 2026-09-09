<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\DsrProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class RepController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of sales reps (DSRs).
     */
    public function index()
    {
        try {
            $reps = User::where('role', 'DSR_REP')
                ->with(['dsrProfile.branch'])
                ->latest()
                ->get();

            return $this->successResponse(
                $reps,
                'Reps retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve reps',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Store a newly created sales rep (User + DsrProfile).
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255|unique:users,email',
                'phone' => 'required|string|max:50|unique:users,phone',
                'password' => 'nullable|string|min:6',
                'rep_code' => 'required|string|max:50|unique:dsr_profiles,rep_code',
                'branch_id' => 'nullable|exists:branches,id',
                'assigned_route_id' => 'nullable|integer',
                'basic_salary' => 'nullable|numeric|min:0',
                'bike_allowance' => 'nullable|numeric|min:0',
                'fuel_allowance' => 'nullable|numeric|min:0',
                'dsr_status' => 'nullable|in:ACTIVE,INACTIVE,ON_TRIP',
                'status' => 'nullable|string|max:50',
            ]);

            $rep = DB::transaction(function () use ($request, $validated) {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
                    'password' => Hash::make($request->input('password', 'rep12345')),
                    'role' => 'DSR_REP',
                    'status' => $request->input('status', 'ACTIVE'),
                ]);

                DsrProfile::create([
                    'user_id' => $user->id,
                    'rep_code' => $validated['rep_code'],
                    'branch_id' => $request->input('branch_id'),
                    'assigned_route_id' => $request->input('assigned_route_id'),
                    'basic_salary' => $request->input('basic_salary', 0.00),
                    'bike_allowance' => $request->input('bike_allowance', 0.00),
                    'fuel_allowance' => $request->input('fuel_allowance', 0.00),
                    'dsr_status' => $request->input('dsr_status', 'ACTIVE'),
                ]);

                return $user->load(['dsrProfile.branch']);
            });

            return $this->successResponse(
                $rep,
                'Rep created successfully',
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
                'Failed to create rep',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Display the specified sales rep.
     */
    public function show($id)
    {
        try {
            $rep = User::where('role', 'DSR_REP')
                ->with(['dsrProfile.branch'])
                ->find($id);

            if (!$rep) {
                return $this->errorResponse(
                    'Rep not found',
                    404
                );
            }

            return $this->successResponse(
                $rep,
                'Rep details retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve rep',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Update the specified sales rep (User + DsrProfile).
     */
    public function update(Request $request, $id)
    {
        try {
            $rep = User::where('role', 'DSR_REP')
                ->with('dsrProfile')
                ->find($id);

            if (!$rep) {
                return $this->errorResponse(
                    'Rep not found',
                    404
                );
            }

            $dsrProfileId = $rep->dsrProfile ? $rep->dsrProfile->id : null;

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'email' => [
                    'sometimes',
                    'required',
                    'email',
                    'max:255',
                    Rule::unique('users', 'email')->ignore($rep->id),
                ],
                'phone' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('users', 'phone')->ignore($rep->id),
                ],
                'password' => 'nullable|string|min:6',
                'rep_code' => [
                    'sometimes',
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('dsr_profiles', 'rep_code')->ignore($dsrProfileId),
                ],
                'branch_id' => 'nullable|exists:branches,id',
                'assigned_route_id' => 'nullable|integer',
                'basic_salary' => 'nullable|numeric|min:0',
                'bike_allowance' => 'nullable|numeric|min:0',
                'fuel_allowance' => 'nullable|numeric|min:0',
                'dsr_status' => 'nullable|in:ACTIVE,INACTIVE,ON_TRIP',
                'status' => 'nullable|string|max:50',
            ]);

            $updatedRep = DB::transaction(function () use ($request, $rep, $validated) {
                $userFields = array_intersect_key($validated, array_flip(['name', 'email', 'phone', 'status']));

                if ($request->filled('password')) {
                    $userFields['password'] = Hash::make($request->password);
                }

                if (!empty($userFields)) {
                    $rep->update($userFields);
                }

                $profileFields = array_intersect_key($validated, array_flip([
                    'rep_code',
                    'branch_id',
                    'assigned_route_id',
                    'basic_salary',
                    'bike_allowance',
                    'fuel_allowance',
                    'dsr_status',
                ]));

                if (!empty($profileFields) || $rep->dsrProfile === null) {
                    $rep->dsrProfile()->updateOrCreate(
                        ['user_id' => $rep->id],
                        $profileFields
                    );
                }

                return $rep->load(['dsrProfile.branch']);
            });

            return $this->successResponse(
                $updatedRep,
                'Rep updated successfully',
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
                'Failed to update rep',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Remove the specified sales rep.
     */
    public function destroy($id)
    {
        try {
            $rep = User::where('role', 'DSR_REP')->find($id);

            if (!$rep) {
                return $this->errorResponse(
                    'Rep not found',
                    404
                );
            }

            $rep->delete();

            return $this->successResponse(
                null,
                'Rep deleted successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to delete rep',
                500,
                $th->getMessage()
            );
        }
    }
}
