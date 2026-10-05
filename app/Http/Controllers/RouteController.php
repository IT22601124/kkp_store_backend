<?php

namespace App\Http\Controllers;

use App\Models\Route;
use Illuminate\Http\Request;

class RouteController extends Controller
{
    private function formatRoute(Route $route): array
    {
        return [
            'id' => (int)$route->id,
            'branch_id' => $route->branch_id ? (int)$route->branch_id : null,
            'branchId' => $route->branch_id ? (int)$route->branch_id : null,
            'route_name' => $route->route_name,
            'routeName' => $route->route_name,
            'route_code' => $route->route_code,
            'routeCode' => $route->route_code,
            'description' => $route->description ?? '',
            'total_shops_count' => isset($route->shops_count) ? (int)$route->shops_count : $route->shops()->count(),
            'totalShopsCount' => isset($route->shops_count) ? (int)$route->shops_count : $route->shops()->count(),
            'assigned_referrer_id' => $route->assigned_referrer_id ? (int)$route->assigned_referrer_id : null,
            'assignedReferrerId' => $route->assigned_referrer_id ? (int)$route->assigned_referrer_id : null,
            'branch' => $route->branch,
            'referrer' => $route->referrer,
            'created_at' => $route->created_at,
            'updated_at' => $route->updated_at
        ];
    }

    public function index(Request $request)
    {
        $query = Route::with(['branch', 'referrer'])->withCount('shops');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }

        if ($request->filled('assigned_referrer_id')) {
            $query->where('assigned_referrer_id', $request->query('assigned_referrer_id'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('route_name', 'like', "%{$search}%")
                  ->orWhere('route_code', 'like', "%{$search}%");
            });
        }

        $routes = $query->orderBy('id', 'desc')->get();
        $formatted = $routes->map(fn($r) => $this->formatRoute($r));

        return response()->json([
            'success' => true,
            'message' => 'Routes retrieved successfully',
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
            'assigned_referrer_id' => 'nullable'
        ]);

        $branchId = $request->input('branch_id') ?? $request->input('branchId') ?? 1;
        $routeName = $request->input('route_name') ?? $request->input('routeName');
        $routeCode = $request->input('route_code') ?? $request->input('routeCode');
        $referrerId = $request->input('assigned_referrer_id') ?? $request->input('assignedReferrerId');

        $route = Route::create([
            'branch_id' => $branchId,
            'route_name' => $routeName,
            'route_code' => $routeCode,
            'description' => $request->input('description', ''),
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
        $route = Route::find($id);
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
        $route = Route::find($id);
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
        $referrerId = $request->has('assigned_referrer_id') || $request->has('assignedReferrerId')
            ? ($request->input('assigned_referrer_id') ?? $request->input('assignedReferrerId'))
            : $route->assigned_referrer_id;

        $route->update([
            'branch_id' => $branchId,
            'route_name' => $routeName,
            'route_code' => $routeCode,
            'description' => $description,
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
        $route = Route::find($id);
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
