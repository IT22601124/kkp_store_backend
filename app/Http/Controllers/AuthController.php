<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

            // 2. Find user by email
            $user = User::where('phone', $request->phone)->first();

            // 3. Check user and password
            if (!$user || !Hash::check($request->password, $user->password)) {
                return $this->errorResponse(
                    'Invalid email or password',
                    401
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

            return $this->errorResponse(
                'Something went wrong',
                500,
                $th->getMessage()
            );
        }
    }
}