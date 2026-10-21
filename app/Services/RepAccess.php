<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class RepAccess
{
    public static function shops(Builder $query, User $user): Builder
    {
        if ($user->role !== 'DSR_REP') {
            return $query;
        }

        $routeId = $user->dsrProfile?->assigned_route_id;

        return $query->where(function (Builder $shops) use ($user, $routeId) {
            $shops->where('created_by', $user->id);
            if ($routeId) {
                $shops->orWhere('route_id', $routeId);
            }
        });
    }

    public static function shop(User $user, int $id): Shop
    {
        return self::shops(Shop::query(), $user)->findOrFail($id);
    }
}
