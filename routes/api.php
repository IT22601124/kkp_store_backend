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
use App\Http\Controllers\RepStockController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\GrnController;
use App\Http\Controllers\v1\MobileRepAuthController;
use App\Http\Controllers\v1\MobileShopController;
use App\Http\Controllers\v1\UserShopController;
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

// Mobile Rep Authentication Routes (v1 & standard)
Route::post('/v1/mobile/rep/login', [MobileRepAuthController::class, 'login']);
Route::post('/v1/rep/login', [MobileRepAuthController::class, 'login']);
Route::get('/v1/mobile/rep/health', [MobileRepAuthController::class, 'checkHealth']);

// Authenticated Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/check-token', [AuthController::class, 'checkToken']);
    Route::post('/check-token', [AuthController::class, 'checkToken']);

    // Mobile Rep Authenticated Routes
    Route::get('/v1/mobile/rep/me', [MobileRepAuthController::class, 'me']);
    Route::post('/v1/mobile/rep/logout', [MobileRepAuthController::class, 'logout']);
    Route::get('/v1/mobile/rep/check-token', [MobileRepAuthController::class, 'checkToken']);
    Route::post('/v1/mobile/rep/check-token', [MobileRepAuthController::class, 'checkToken']);

    // Fetch Shops By created_by Route (Uses Authorization Bearer Token)
    Route::get('/v1/shops/created-by', [UserShopController::class, 'getShopsByCreatedBy']);

    // Mobile Shop Routes (v1 & standard)
    Route::get('/v1/mobile/shops', [MobileShopController::class, 'index']);
    Route::post('/v1/mobile/shops', [MobileShopController::class, 'store']);
    Route::get('/v1/mobile/shops/{id}', [MobileShopController::class, 'show']);
    Route::delete('/v1/mobile/shops/{id}', [MobileShopController::class, 'destroy']);

    Route::get('/v1/shops', [MobileShopController::class, 'index']);
    Route::post('/v1/shops', [MobileShopController::class, 'store']);
    Route::get('/v1/shops/{id}', [MobileShopController::class, 'show']);
    Route::delete('/v1/shops/{id}', [MobileShopController::class, 'destroy']);

    Route::apiResource('shops', ShopController::class);
    Route::post('/shops/{id}/settle-credit', [ShopController::class, 'settleCredit']);

    // GPS Location Routes
    Route::apiResource('gps-locations', ShopGpsLocationController::class);
    Route::get('/shops/{id}/gps-location', [ShopGpsLocationController::class, 'getByShop']);
    Route::post('/shops/{id}/gps-location', [ShopGpsLocationController::class, 'updateForShop']);

    // Operational Resources
    Route::apiResource('dsr-trips', DsrTripController::class);
    Route::apiResource('targets', TargetController::class);
    Route::apiResource('payroll', PayrollController::class);

    Route::apiResource('branches', BranchController::class);
    Route::apiResource('reps', RepController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('items', ItemController::class);
    Route::apiResource('routes', RouteController::class);

    // Stock & Inventory Management Routes
    Route::get('/stocks', [StockController::class, 'index']);
    Route::get('/stocks/{id}', [StockController::class, 'show']);
    Route::get('/rep-stocks', [RepStockController::class, 'index']);
    Route::get('/rep-stocks/rep/{repId}', [RepStockController::class, 'getByRep']);
    Route::get('/rep-stocks/{id}', [RepStockController::class, 'show']);
    Route::get('/v1/rep-stocks', [RepStockController::class, 'index']);
    Route::get('/v1/rep-stocks/rep/{repId}', [RepStockController::class, 'getByRep']);
    Route::get('/v1/rep-stocks/{id}', [RepStockController::class, 'show']);
    Route::get('/stock-movements', [StockMovementController::class, 'index']);
    Route::apiResource('stock-transfers', StockTransferController::class);
    Route::apiResource('stock-adjustments', StockAdjustmentController::class);
    Route::apiResource('grn', GrnController::class);
});
