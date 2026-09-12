<?php

namespace App\Http\Controllers;

use App\Models\ShopGpsLocation;
use App\Models\Shop;
use Illuminate\Http\Request;

class ShopGpsLocationController extends Controller
{
    private function formatGps(ShopGpsLocation $gps): array
    {
        return [
            'id' => (int)$gps->id,
            'shop_id' => (int)$gps->shop_id,
            'shopId' => (int)$gps->shop_id,
            'latitude' => $gps->latitude !== null ? (float)$gps->latitude : null,
            'longitude' => $gps->longitude !== null ? (float)$gps->longitude : null,
            'accuracy' => $gps->accuracy !== null ? (float)$gps->accuracy : null,
            'captured_by_user_id' => $gps->captured_by_user_id ? (int)$gps->captured_by_user_id : null,
            'capturedByUserId' => $gps->captured_by_user_id ? (int)$gps->captured_by_user_id : null,
            'address_text' => $gps->address_text ?? '',
            'addressText' => $gps->address_text ?? '',
            'is_verified' => (bool)$gps->is_verified,
            'isVerified' => (bool)$gps->is_verified,
            'created_at' => $gps->created_at,
            'updated_at' => $gps->updated_at
        ];
    }

    public function index()
    {
        $locations = ShopGpsLocation::orderBy('id', 'desc')->get();
        return response()->json([
            'success' => true,
            'data' => $locations->map(fn($g) => $this->formatGps($g))
        ]);
    }

    public function store(Request $request)
    {
        $shopId = $request->input('shop_id') ?? $request->input('shopId');
        $lat = $request->input('latitude') ?? $request->input('lat');
        $lng = $request->input('longitude') ?? $request->input('lng');

        $gps = ShopGpsLocation::create([
            'shop_id' => $shopId,
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy' => $request->input('accuracy'),
            'captured_by_user_id' => $request->input('captured_by_user_id') ?? $request->input('capturedByUserId'),
            'address_text' => $request->input('address_text') ?? $request->input('addressText') ?? $request->input('address'),
            'is_verified' => $request->input('is_verified', true)
        ]);

        if ($shopId) {
            $shop = Shop::find($shopId);
            if ($shop) {
                $shop->update(['latitude' => $lat, 'longitude' => $lng]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Shop GPS location recorded successfully',
            'data' => $this->formatGps($gps)
        ], 201);
    }

    public function show($id)
    {
        $gps = ShopGpsLocation::find($id);
        if (!$gps) {
            return response()->json(['success' => false, 'message' => 'GPS location not found'], 404);
        }
        return response()->json(['success' => true, 'data' => $this->formatGps($gps)]);
    }

    public function getByShop($shopId)
    {
        $gps = ShopGpsLocation::where('shop_id', $shopId)->latest()->first();
        if (!$gps) {
            return response()->json(['success' => false, 'message' => 'No GPS location recorded for this shop'], 404);
        }
        return response()->json(['success' => true, 'data' => $this->formatGps($gps)]);
    }

    public function updateForShop(Request $request, $shopId)
    {
        $lat = $request->input('latitude') ?? $request->input('lat');
        $lng = $request->input('longitude') ?? $request->input('lng');

        $gps = ShopGpsLocation::updateOrCreate(
            ['shop_id' => $shopId],
            [
                'latitude' => $lat,
                'longitude' => $lng,
                'accuracy' => $request->input('accuracy'),
                'captured_by_user_id' => $request->input('captured_by_user_id') ?? $request->input('capturedByUserId'),
                'address_text' => $request->input('address_text') ?? $request->input('addressText') ?? $request->input('address'),
                'is_verified' => true
            ]
        );

        $shop = Shop::find($shopId);
        if ($shop) {
            $shop->update(['latitude' => $lat, 'longitude' => $lng]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Shop GPS location updated successfully',
            'data' => $this->formatGps($gps)
        ]);
    }

    public function destroy($id)
    {
        $gps = ShopGpsLocation::find($id);
        if ($gps) $gps->delete();
        return response()->json(['success' => true, 'message' => 'GPS location record deleted']);
    }
}
