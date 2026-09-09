<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'referrer_id',
        'item_id',
        'quantity',
        'reserved_quantity',
        'unit_cost',
        'unit_price',
        'total_value',
        'batch_number',
        'status',
        'last_audited_at'
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class, 'stock_id');
    }
}
