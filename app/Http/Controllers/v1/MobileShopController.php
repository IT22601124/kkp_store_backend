<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Shop;
use App\Models\ShopGpsLocation;
use App\Models\DistributionRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MobileShopController extends Controller
{
    use ApiResponse;

    /**
     * Format shop for mobile API response (providing both snake_case and camelCase keys).
     */
    private function formatShop(Shop $shop): array
    {
        return [
            'id' => (int)$shop->id,
            'route_id' => $shop->route_id ? (int)$shop->route_id : 1,
            'routeId' => $shop->route_id ? (int)$shop->route_id : 1,
            'shop_code' => $shop->shop_code,
            'shopCode' => $shop->shop_code,
            'shop_name' => $shop->shop_name,
            'shopName' => $shop->shop_name,
            'owner_name' => $shop->owner_name ?? '',
            'ownerName' => $shop->owner_name ?? '',
            'phone' => $shop->phone ?? '',
            'address' => $shop->address ?? '',
            'latitude' => $shop->latitude !== null ? (float)$shop->latitude : null,
            'longitude' => $shop->longitude !== null ? (float)$shop->longitude : null,
            'credit_limit' => (float)$shop->credit_limit,
            'creditLimit' => (float)$shop->credit_limit,
            'current_credit_balance' => (float)$shop->current_credit_balance,
            'currentCreditBalance' => (float)$shop->current_credit_balance,
            'status' => strtoupper($shop->status ?? 'GOOD'),
            'created_by' => $shop->created_by ? (int)$shop->created_by : null,
            'createdBy' => $shop->created_by ? (int)$shop->created_by : null,
            'created_at' => $shop->created_at,
            'updated_at' => $shop->updated_at
        ];
    }

    /**
     * Get list of shops for mobile application.
     */
    public function index(Request $request)
    {
        try {
            $query = Shop::orderBy('id', 'desc');

            if ($request->has('route_id') || $request->has('routeId')) {
                $routeId = $request->input('route_id') ?? $request->input('routeId');
                $query->where('route_id', $routeId);
            }

            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('shop_name', 'like', "%{$search}%")
                      ->orWhere('shop_code', 'like', "%{$search}%")
                      ->orWhere('owner_name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            $shops = $query->get();
            $formatted = $shops->map(fn($s) => $this->formatShop($s));

            return $this->successResponse(
                $formatted,
                'Shops retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            Log::error('MobileShopController index error: ' . $th->getMessage());
            return $this->errorResponse(
                'Failed to retrieve shops',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Create a new shop for mobile application.
     */
    public function store(Request $request)
    {
        try {
            // Flexible input mapping for both snake_case and camelCase
            $shopName = $request->input('shop_name') ?? $request->input('shopName') ?? $request->input('name');
            $ownerName = $request->input('owner_name') ?? $request->input('ownerName');
            $phone = $request->input('phone') ?? $request->input('contact_number') ?? $request->input('mobile');
            $address = $request->input('address');
            $routeId = $request->input('route_id') ?? $request->input('routeId') ?? 1;
            $shopCode = $request->input('shop_code') ?? $request->input('shopCode');
            $lat = $request->input('latitude') ?? $request->input('lat');
            $lng = $request->input('longitude') ?? $request->input('lng');
            $accuracy = $request->input('accuracy');
            $creditLimit = $request->input('credit_limit') ?? $request->input('creditLimit') ?? 100000.00;
            $status = $request->input('status') ?? 'GOOD';
            $createdBy = $request->user() ? $request->user()->id : ($request->input('created_by') ?? $request->input('createdBy'));

            // Validate mandatory fields
            if (empty($shopName)) {
                return $this->errorResponse('Shop name (shop_name / shopName) is required', 422);
            }

            // Auto-generate shop code if missing
            if (empty($shopCode)) {
                $count = Shop::count() + 1;
                $prefix = 'SHP-' . date('Ymd') . '-';
                $shopCode = $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
                
                // Ensure uniqueness
                while (Shop::where('shop_code', $shopCode)->exists()) {
                    $count++;
                    $shopCode = $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
                }
            } else {
                $shopCode = strtoupper($shopCode);
                // Check if shop_code is already taken
                if (Shop::where('shop_code', $shopCode)->exists()) {
                    return $this->errorResponse("Shop code '{$shopCode}' already exists", 422);
                }
            }

            // Create shop model
            $shop = Shop::create([
                'route_id' => $routeId,
                'shop_code' => $shopCode,
                'shop_name' => $shopName,
                'owner_name' => $ownerName ?? '',
                'phone' => $phone ?? '',
                'address' => $address ?? '',
                'latitude' => $lat !== null ? (float)$lat : null,
                'longitude' => $lng !== null ? (float)$lng : null,
                'credit_limit' => (float)$creditLimit,
                'current_credit_balance' => 0.00,
                'status' => strtoupper($status),
                'created_by' => $createdBy
            ]);

            // If GPS coordinates provided, record in shop_gps_locations table as well
            if ($lat !== null && $lng !== null) {
                $userId = null;
                if ($request->user()) {
                    $userId = $request->user()->id;
                }

                ShopGpsLocation::create([
                    'shop_id' => $shop->id,
                    'latitude' => (float)$lat,
                    'longitude' => (float)$lng,
                    'accuracy' => $accuracy !== null ? (float)$accuracy : null,
                    'captured_by_user_id' => $userId,
                    'address_text' => $address ?? '',
                    'is_verified' => true
                ]);
            }

            // Increment route's shop count if route exists
            if ($routeId) {
                $route = DistributionRoute::find($routeId);
                if ($route) {
                    $route->increment('total_shops_count');
                }
            }

            return $this->successResponse(
                $this->formatShop($shop),
                'Shop created successfully via mobile API',
                201
            );

        } catch (Throwable $th) {
            Log::error('MobileShopController store error: ' . $th->getMessage());
            return $this->errorResponse(
                'Failed to create shop',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Get details of a single shop for mobile.
     */
    public function show($id)
    {
        try {
            $shop = Shop::find($id);
            if (!$shop) {
                return $this->errorResponse('Shop not found', 404);
            }

            return $this->successResponse(
                $this->formatShop($shop),
                'Shop details retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve shop details',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Delete a shop record for mobile application.
     */
    public function destroy($id)
    {
        try {
            $shop = Shop::find($id);
            if (!$shop) {
                return $this->errorResponse('Shop not found', 404);
            }

            $routeId = $shop->route_id;
            
            // Delete associated GPS location records if any
            ShopGpsLocation::where('shop_id', $id)->delete();

            // Delete shop
            $shop->delete();

            // Decrement total_shops_count on route if applicable
            if ($routeId) {
                $route = DistributionRoute::find($routeId);
                if ($route && $route->total_shops_count > 0) {
                    $route->decrement('total_shops_count');
                }
            }

            return $this->successResponse(
                ['id' => (int)$id],
                'Shop deleted successfully',
                200
            );

        } catch (Throwable $th) {
            Log::error('MobileShopController destroy error: ' . $th->getMessage());
            return $this->errorResponse(
                'Failed to delete shop',
                500,
                $th->getMessage()
            );
        }
    }
}
