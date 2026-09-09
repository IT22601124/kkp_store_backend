<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GrnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'goods_received_note_id',
        'item_id',
        'quantity_received',
        'unit_price',
        'total_amount'
    ];

    public function grn()
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'goods_received_note_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
