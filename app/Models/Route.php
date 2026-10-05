<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Route extends Model
{
    use HasFactory;

    protected $table = 'routes';

    protected $fillable = [
        'branch_id',
        'route_name',
        'route_code',
        'description',
        'assigned_referrer_id',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'assigned_referrer_id');
    }

    public function shops()
    {
        return $this->hasMany(Shop::class, 'route_id');
    }
}
