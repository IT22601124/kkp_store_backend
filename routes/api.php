<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\RepController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\ShopGpsLocationController;
use App\Http\Controllers\DsrTripController;
use App\Http\Controllers\TargetController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\GrnController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public Endpoints
Route::get('/health', [HealthController::class, 'checkHealth']);
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::get('/login', function() { return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401); });

// Public & Development Resource Routes
Route::apiResource('shops', ShopController::class);
Route::post('/shops/{id}/settle-credit', [ShopController::class, 'settleCredit']);
Route::apiResource('gps-locations', ShopGpsLocationController::class);
Route::get('/shops/{id}/gps-location', [ShopGpsLocationController::class, 'getByShop']);
Route::post('/shops/{id}/gps-location', [ShopGpsLocationController::class, 'updateForShop']);

Route::apiResource('dsr-trips', DsrTripController::class);
Route::apiResource('targets', TargetController::class);
Route::apiResource('payroll', PayrollController::class);

// Authenticated Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/check-token', [AuthController::class, 'checkToken']);
    Route::post('/check-token', [AuthController::class, 'checkToken']);
    Route::apiResource('branches', BranchController::class);
    Route::apiResource('reps', RepController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('items', ItemController::class);
    Route::apiResource('routes', RouteController::class);

    // Stock & Inventory Management Routes
    Route::get('/stocks', [StockController::class, 'index']);
    Route::get('/stocks/{id}', [StockController::class, 'show']);
    Route::get('/stock-movements', [StockMovementController::class, 'index']);
    Route::apiResource('stock-transfers', StockTransferController::class);
    Route::apiResource('stock-adjustments', StockAdjustmentController::class);
    Route::apiResource('grn', GrnController::class);
});
