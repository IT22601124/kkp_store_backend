<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailySettlementItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'daily_settlement_id',
        'item_id',
        'item_name',
        'item_code',
        'sold_quantity',
        'total_revenue',
    ];

    protected $casts = [
        'total_revenue' => 'decimal:2',
    ];

    public function dailySettlement()
    {
        return $this->belongsTo(DailySettlement::class, 'daily_settlement_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
