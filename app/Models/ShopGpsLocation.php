<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopGpsLocation extends Model
{
    use HasFactory;

    protected $table = 'shop_gps_locations';

    protected $fillable = [
        'shop_id',
        'latitude',
        'longitude',
        'accuracy',
        'captured_by_user_id',
        'address_text',
        'is_verified'
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function capturedBy()
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }
}
