<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcceptedRequestItem extends Model
{
    use HasFactory;

    protected $table = 'accepted_request_items';

    protected $fillable = [
        'rep_stock_id',
        'item_id',
        'quantity',
        'batch_number',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function repStock()
    {
        return $this->belongsTo(RepStock::class, 'rep_stock_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
