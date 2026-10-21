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

            // 2. Find user by phone, local format, international format, or email
            $input = trim($request->phone);
            $digits = preg_replace('/[^0-9]/', '', $input);
            $local = preg_replace('/^94/', '0', $digits);
            $intl = preg_replace('/^0/', '+94', $local);

            $user = User::where(function ($q) use ($input, $digits, $local, $intl) {
                $q->where('phone', $input)
                  ->orWhere('phone', $digits)
                  ->orWhere('phone', $local)
                  ->orWhere('phone', $intl)
                  ->orWhere('phone', '+' . $digits)
                  ->orWhere('email', $input);
            })->first();

            // 3. Check user existence and password
            $passwordValid = $user && Hash::check($request->password, $user->password);

            if (!$user || !$passwordValid) {
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

            if (strtoupper($user->status) !== 'ACTIVE') {
                return $this->errorResponse('Your account is inactive', 403);
            }

            // 4. Create authentication token
            $token = $user->createToken('auth_token')->plainTextToken;

            // Store or update FCM device token if provided
            $fcmToken = $request->input('fcm_token') ?? $request->input('device_token');
            if (!empty($fcmToken)) {
                $user->update(['fcm_token' => $fcmToken]);
            }
            // 5. Return successful response
            return $this->successResponse(
                [
                    'user' => $user,
                    'token' => $token,
                ],
                'Login successful',
                200
            );

        } catch (\Illuminate\Validation\ValidationException $th) {
            throw $th;
        } catch (Throwable $th) {
            Log::info($th->getMessage());
            return $this->errorResponse(
                'Something went wrong',
                500,
                $th->getMessage()
            );
        }
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['success' => true, 'message' => 'Logged out successfully']);
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
        } catch (\Illuminate\Validation\ValidationException $th) {
            throw $th;
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Invalid or expired token',
                401,
                $th->getMessage()
            );
        }
    }
}