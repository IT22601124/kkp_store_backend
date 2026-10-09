<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailySettlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'settlement_code',
        'rep_id',
        'dsr_trip_id',
        'settlement_date',
        'shops_visited_count',
        'total_sales_value',
        'total_cash_collected',
        'total_cheques_collected',
        'total_online_collected',
        'total_credit_issued',
        'ending_shop_credit',
        'status',
        'notes',
        'submitted_at',
    ];

    protected $casts = [
        'settlement_date' => 'date',
        'submitted_at' => 'datetime',
        'total_sales_value' => 'decimal:2',
        'total_cash_collected' => 'decimal:2',
        'total_cheques_collected' => 'decimal:2',
        'total_online_collected' => 'decimal:2',
        'total_credit_issued' => 'decimal:2',
        'ending_shop_credit' => 'decimal:2',
    ];

    public function rep()
    {
        return $this->belongsTo(User::class, 'rep_id');
    }

    public function dsrTrip()
    {
        return $this->belongsTo(DsrTrip::class, 'dsr_trip_id');
    }

    public function items()
    {
        return $this->hasMany(DailySettlementItem::class, 'daily_settlement_id');
    }
}
