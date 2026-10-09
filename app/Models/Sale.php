<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $table = 'sales';

    protected $fillable = [
        'sale_code',
        'rep_id',
        'user_id',
        'shop_id',
        'branch_id',
        'dsr_trip_id',
        'sale_date',
        'payment_type',
        'status',
        'subtotal',
        'discount',
        'tax',
        'total_amount',
        'paid_amount',
        'due_amount',
        'notes',
    ];

    protected $casts = [
        'sale_date' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_amount' => 'decimal:2',
    ];

    public function salesItems()
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function rep()
    {
        return $this->belongsTo(User::class, 'rep_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function dsrTrip()
    {
        return $this->belongsTo(DsrTrip::class, 'dsr_trip_id');
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class, 'sale_id');
    }
}
