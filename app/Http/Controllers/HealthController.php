<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    use ApiResponse;

    /**
     * Check application API health status.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkHealth()
    {
        return $this->successResponse(
            [
                'status' => 'healthy',
                'service' => 'KKP Store Backend API',
                'timestamp' => now()->toIso8601String(),
                'environment' => config('app.env', 'production'),
            ],
            'API is healthy and operational',
            200
        );
    }
}
