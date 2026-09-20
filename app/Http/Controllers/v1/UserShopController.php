<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class UserShopController extends Controller
{
    use ApiResponse;

    /**
     * Format shop for API response (providing both snake_case and camelCase keys).
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
     * Fetch shops created by user using the created_by column.
     * Finds the user directly from the Authorization Bearer Token.
     */
    public function getShopsByCreatedBy(Request $request)
    {
        try {
            // Find user from Sanctum Authorization Bearer token
            $user = $request->user();

            if (!$user) {
                return $this->errorResponse('Unauthenticated or user not found', 401);
            }

            $userId = $user->id;
            Log::info("User found from authorization token: " . $userId);

            $query = Shop::where('created_by', $userId)->orderBy('id', 'desc');

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
                [
                    'created_by' => (int)$userId,
                    'user_id' => (int)$userId,
                    'user_name' => $user->name ?? '',
                    'total_shops' => $shops->count(),
                    'shops' => $formatted
                ],
                "Shops created by user ID {$userId} retrieved successfully",
                200
            );

        } catch (Throwable $th) {
            Log::error("UserShopController getShopsByCreatedBy error: " . $th->getMessage());
            return $this->errorResponse(
                'Failed to fetch shops by created_by user ID',
                500,
                $th->getMessage()
            );
        }
    }
}
