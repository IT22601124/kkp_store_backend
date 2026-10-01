<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockRequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_request_id',
        'item_id',
        'requested_quantity',
        'approved_quantity',
    ];

    protected $casts = [
        'requested_quantity' => 'integer',
        'approved_quantity' => 'integer',
    ];

    public function stockRequest()
    {
        return $this->belongsTo(StockRequest::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
