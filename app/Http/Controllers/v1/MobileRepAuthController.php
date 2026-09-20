<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class MobileRepAuthController extends Controller
{
    use ApiResponse;

    /**
     * Handle mobile sales rep (DSR_REP) authentication.
     */
    public function login(Request $request)
    {
        try {
            $identifier = $request->input('login') ?? $request->input('phone') ?? $request->input('rep_code');

            if (empty($identifier)) {
                return $this->errorResponse(
                    'Phone number, rep code, or email is required',
                    422
                );
            }

            if (empty($request->input('password'))) {
                return $this->errorResponse(
                    'Password is required',
                    422
                );
            }

            // Find user by phone, email, or DsrProfile rep_code
            $user = User::where(function ($query) use ($identifier) {
                $query->where('phone', $identifier)
                      ->orWhere('email', $identifier);
            })->orWhereHas('dsrProfile', function ($query) use ($identifier) {
                $query->where('rep_code', $identifier);
            })->first();

            // Check user existence and password
            if (!$user || !Hash::check($request->input('password'), $user->password)) {
                return $this->errorResponse(
                    'Invalid credentials',
                    401
                );
            }

            // Check if user has DSR_REP role
            if ($user->role !== 'DSR_REP') {
                return $this->errorResponse(
                    'Access denied. Only DSR sales rep accounts are allowed to log into the mobile app.',
                    403
                );
            }

            // Check user active status
            if (strtoupper($user->status) !== 'ACTIVE') {
                return $this->errorResponse(
                    'Your account is currently inactive. Please contact system administrator.',
                    403
                );
            }

            // Load DSR profile and branch relations
            $user->load(['dsrProfile.branch']);

            if ($user->dsrProfile && strtoupper($user->dsrProfile->dsr_status) === 'INACTIVE') {
                return $this->errorResponse(
                    'Your sales rep profile is currently inactive.',
                    403
                );
            }

            // Create Sanctum authentication token for mobile app
            $token = $user->createToken('mobile_rep_token')->plainTextToken;

            return $this->successResponse(
                [
                    'user' => $user,
                    'token' => $token,
                ],
                'Rep login successful',
                200
            );

        } catch (Throwable $th) {
            Log::error('MobileRepAuthController login error: ' . $th->getMessage());
            return $this->errorResponse(
                'Something went wrong during mobile rep authentication',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Get authenticated mobile rep profile information.
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user()->load(['dsrProfile.branch']);

            return $this->successResponse(
                [
                    'user' => $user,
                ],
                'Profile retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve profile',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Revoke current mobile access token (Logout).
     */
    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return $this->successResponse(
                null,
                'Logged out successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to log out',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Check authentication token validity for mobile sales rep.
     */
    public function checkToken(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return $this->errorResponse(
                    'Invalid or expired token',
                    401
                );
            }

            if ($user->role !== 'DSR_REP') {
                return $this->errorResponse(
                    'Access denied. Only DSR sales rep accounts are allowed.',
                    403
                );
            }

            $user->load(['dsrProfile.branch']);

            return $this->successResponse(
                [
                    'valid' => true,
                    'user' => $user,
                ],
                'Token is valid',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Invalid or expired token',
                401,
                $th->getMessage()
            );
        }
    }

    /**
     * Check mobile rep auth service health status.
     */
    public function checkHealth()
    {
        return $this->successResponse(
            [
                'status' => 'healthy',
                'service' => 'KKP Mobile Rep Auth Controller (v1)',
                'timestamp' => now()->toIso8601String(),
            ],
            'Mobile rep auth service is healthy',
            200
        );
    }
}
