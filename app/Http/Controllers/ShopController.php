<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\DistributionRoute;
use Illuminate\Http\Request;

class ShopController extends Controller
{
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
            'created_at' => $shop->created_at,
            'updated_at' => $shop->updated_at
        ];
    }

    public function index()
    {
        $shops = Shop::orderBy('id', 'desc')->get();
        $formatted = $shops->map(fn($s) => $this->formatShop($s));

        return response()->json([
            'success' => true,
            'data' => $formatted
        ]);
    }

    public function store(Request $request)
    {
        $routeId = $request->input('route_id') ?? $request->input('routeId') ?? 1;
        $shopCode = $request->input('shop_code') ?? $request->input('shopCode') ?? ('SHP-' . rand(100, 999));
        $shopName = $request->input('shop_name') ?? $request->input('shopName') ?? $request->input('name') ?? 'Retail Outlet';
        $ownerName = $request->input('owner_name') ?? $request->input('ownerName') ?? '';
        $phone = $request->input('phone') ?? '';
        $address = $request->input('address') ?? '';
        $lat = $request->input('latitude') ?? $request->input('lat');
        $lng = $request->input('longitude') ?? $request->input('lng');
        $creditLimit = $request->input('credit_limit') ?? $request->input('creditLimit') ?? 100000;
        $currentCreditBalance = $request->input('current_credit_balance') ?? $request->input('currentCreditBalance') ?? 0;
        $status = $request->input('status') ?? 'GOOD';

        $shop = Shop::create([
            'route_id' => $routeId,
            'shop_code' => strtoupper($shopCode),
            'shop_name' => $shopName,
            'owner_name' => $ownerName,
            'phone' => $phone,
            'address' => $address,
            'latitude' => $lat,
            'longitude' => $lng,
            'credit_limit' => $creditLimit,
            'current_credit_balance' => $currentCreditBalance,
            'status' => strtoupper($status)
        ]);

        if ($routeId) {
            $route = DistributionRoute::find($routeId);
            if ($route) {
                $route->increment('total_shops_count');
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Shop outlet created successfully',
            'data' => $this->formatShop($shop)
        ], 201);
    }

    public function show($id)
    {
        $shop = Shop::find($id);
        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'Shop outlet not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatShop($shop)
        ]);
    }

    public function update(Request $request, $id)
    {
        $shop = Shop::find($id);
        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'Shop outlet not found'
            ], 404);
        }

        $routeId = $request->input('route_id') ?? $request->input('routeId') ?? $shop->route_id;
        $shopCode = $request->input('shop_code') ?? $request->input('shopCode') ?? $shop->shop_code;
        $shopName = $request->input('shop_name') ?? $request->input('shopName') ?? $request->input('name') ?? $shop->shop_name;
        $ownerName = $request->has('owner_name') || $request->has('ownerName') ? ($request->input('owner_name') ?? $request->input('ownerName')) : $shop->owner_name;
        $phone = $request->has('phone') ? $request->input('phone') : $shop->phone;
        $address = $request->has('address') ? $request->input('address') : $shop->address;
        $lat = $request->has('latitude') || $request->has('lat') ? ($request->input('latitude') ?? $request->input('lat')) : $shop->latitude;
        $lng = $request->has('longitude') || $request->has('lng') ? ($request->input('longitude') ?? $request->input('lng')) : $shop->longitude;
        $creditLimit = $request->input('credit_limit') ?? $request.input('creditLimit') ?? $shop->credit_limit;
        $currentCreditBalance = $request->input('current_credit_balance') ?? $request->input('currentCreditBalance') ?? $shop->current_credit_balance;
        $status = $request->input('status') ?? $shop->status;

        $shop->update([
            'route_id' => $routeId,
            'shop_code' => strtoupper($shopCode),
            'shop_name' => $shopName,
            'owner_name' => $ownerName,
            'phone' => $phone,
            'address' => $address,
            'latitude' => $lat,
            'longitude' => $lng,
            'credit_limit' => $creditLimit,
            'current_credit_balance' => $currentCreditBalance,
            'status' => strtoupper($status)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Shop outlet updated successfully',
            'data' => $this->formatShop($shop)
        ]);
    }

    public function destroy($id)
    {
        $shop = Shop::find($id);
        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'Shop outlet not found'
            ], 404);
        }

        $routeId = $shop->route_id;
        $shop->delete();

        if ($routeId) {
            $route = DistributionRoute::find($routeId);
            if ($route && $route->total_shops_count > 0) {
                $route->decrement('total_shops_count');
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Shop outlet deleted successfully'
        ]);
    }

    public function settleCredit(Request $request, $id)
    {
        $shop = Shop::find($id);
        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'Shop outlet not found'
            ], 404);
        }

        $paymentAmount = (float)($request->input('amount') ?? $request->input('payment_amount') ?? $request->input('paymentAmount') ?? 0);
        $newBalance = max(0, (float)$shop->current_credit_balance - $paymentAmount);

        if ($request->has('current_credit_balance') || $request->has('currentCreditBalance')) {
            $newBalance = (float)($request->input('current_credit_balance') ?? $request->input('currentCreditBalance'));
        }

        $shop->update([
            'current_credit_balance' => $newBalance
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Credit settlement recorded successfully',
            'data' => $this->formatShop($shop)
        ]);
    }
}
