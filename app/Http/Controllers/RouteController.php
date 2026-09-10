<?php

namespace App\Http\Controllers;

use App\Models\DistributionRoute;
use Illuminate\Http\Request;

class RouteController extends Controller
{
    private function formatRoute(DistributionRoute $route): array
    {
        return [
            'id' => $route->id,
            'branch_id' => $route->branch_id,
            'branchId' => $route->branch_id,
            'route_name' => $route->route_name,
            'routeName' => $route->route_name,
            'route_code' => $route->route_code,
            'routeCode' => $route->route_code,
            'description' => $route->description ?? '',
            'total_shops_count' => (int)$route->total_shops_count,
            'totalShopsCount' => (int)$route->total_shops_count,
            'assigned_referrer_id' => $route->assigned_referrer_id ? (int)$route->assigned_referrer_id : null,
            'assignedReferrerId' => $route->assigned_referrer_id ? (int)$route->assigned_referrer_id : null,
            'created_at' => $route->created_at,
            'updated_at' => $route->updated_at
        ];
    }

    public function index()
    {
        $routes = DistributionRoute::orderBy('id', 'desc')->get();
        $formatted = $routes->map(fn($r) => $this->formatRoute($r));

        return response()->json([
            'success' => true,
            'data' => $formatted
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required',
            'route_name' => 'required|string|max:255',
            'route_code' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'total_shops_count' => 'nullable|integer',
            'assigned_referrer_id' => 'nullable'
        ]);

        $branchId = $request->input('branch_id') ?? $request->input('branchId') ?? 1;
        $routeName = $request->input('route_name') ?? $request->input('routeName');
        $routeCode = $request->input('route_code') ?? $request->input('routeCode');
        $shopsCount = $request->input('total_shops_count') ?? $request->input('totalShopsCount') ?? 0;
        $referrerId = $request->input('assigned_referrer_id') ?? $request->input('assignedReferrerId');

        $route = DistributionRoute::create([
            'branch_id' => $branchId,
            'route_name' => $routeName,
            'route_code' => $routeCode,
            'description' => $request->input('description', ''),
            'total_shops_count' => $shopsCount,
            'assigned_referrer_id' => $referrerId
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Distribution route created successfully',
            'data' => $this->formatRoute($route)
        ], 201);
    }

    public function show($id)
    {
        $route = DistributionRoute::find($id);
        if (!$route) {
            return response()->json([
                'success' => false,
                'message' => 'Route not found'
            ], 444);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatRoute($route)
        ]);
    }

    public function update(Request $request, $id)
    {
        $route = DistributionRoute::find($id);
        if (!$route) {
            return response()->json([
                'success' => false,
                'message' => 'Route not found'
            ], 404);
        }

        $branchId = $request->input('branch_id') ?? $request->input('branchId') ?? $route->branch_id;
        $routeName = $request->input('route_name') ?? $request->input('routeName') ?? $route->route_name;
        $routeCode = $request->input('route_code') ?? $request->input('routeCode') ?? $route->route_code;
        $description = $request->has('description') ? $request->input('description') : $route->description;
        $shopsCount = $request->input('total_shops_count') ?? $request->input('totalShopsCount') ?? $route->total_shops_count;
        $referrerId = $request->has('assigned_referrer_id') || $request->has('assignedReferrerId')
            ? ($request->input('assigned_referrer_id') ?? $request->input('assignedReferrerId'))
            : $route->assigned_referrer_id;

        $route->update([
            'branch_id' => $branchId,
            'route_name' => $routeName,
            'route_code' => $routeCode,
            'description' => $description,
            'total_shops_count' => $shopsCount,
            'assigned_referrer_id' => $referrerId
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Distribution route updated successfully',
            'data' => $this->formatRoute($route)
        ]);
    }

    public function destroy($id)
    {
        $route = DistributionRoute::find($id);
        if (!$route) {
            return response()->json([
                'success' => false,
                'message' => 'Route not found'
            ], 404);
        }

        $route->delete();

        return response()->json([
            'success' => true,
            'message' => 'Route deleted successfully'
        ]);
    }
}
