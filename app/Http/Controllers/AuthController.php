<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Http\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request)
    {
        try {

            // 1. Validate request
            $request->validate([
                'phone' => 'required|string',
                'password' => 'required|string',
            ]);

            // 2. Find user by phone
            $user = User::where('phone', $request->phone)->first();

            // 3. Check user existence and password
            if (!$user || !Hash::check($request->password, $user->password)) {
                return $this->errorResponse(
                    'Invalid phone number or password',
                    401
                );
            }

            // 4. Check if user is an AGENT
            if ($user->role !== 'AGENT') {
                return $this->errorResponse(
                    'Access denied. Only agent accounts are allowed to log into this system.',
                    403
                );
            }

            // 4. Create authentication token
            $token = $user->createToken('auth_token')->plainTextToken;

            // 5. Return successful response
            return $this->successResponse(
                [
                    'user' => $user,
                    'token' => $token,
                ],
                'Login successful',
                200
            );

        } catch (Throwable $th) {
            Log::info($th->getMessage());
            return $this->errorResponse(
                'Something went wrong',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Check authentication service health status
     */
    public function checkHealth()
    {
        return $this->successResponse(
            [
                'status' => 'healthy',
                'service' => 'KKP Store Auth Controller',
                'timestamp' => now()->toIso8601String(),
            ],
            'Auth service is healthy',
            200
        );
    }

    /**
     * Check authentication token validity and return user details.
     */
    public function checkToken(Request $request)
    {
        try {
            $user = $request->user();

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
}