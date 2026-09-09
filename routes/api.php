<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\RepController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public Endpoints
Route::get('/health', [HealthController::class, 'checkHealth']);
Route::post('/login', [AuthController::class, 'login']);

// Authenticated Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/check-token', [AuthController::class, 'checkToken']);
    Route::post('/check-token', [AuthController::class, 'checkToken']);
    Route::apiResource('branches', BranchController::class);
    Route::apiResource('reps', RepController::class);
});


