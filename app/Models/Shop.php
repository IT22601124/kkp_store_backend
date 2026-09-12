<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    use HasFactory;

    protected $table = 'shops';

    protected $fillable = [
        'route_id',
        'shop_code',
        'shop_name',
        'owner_name',
        'phone',
        'address',
        'latitude',
        'longitude',
        'credit_limit',
        'current_credit_balance',
        'status'
    ];

    public function route()
    {
        return $this->belongsTo(DistributionRoute::class, 'route_id');
    }

    public function gpsLocation()
    {
        return $this->hasOne(ShopGpsLocation::class, 'shop_id');
    }
}
