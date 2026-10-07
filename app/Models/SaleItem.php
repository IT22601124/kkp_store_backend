<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    use HasFactory;

    protected $table = 'sales_items';

    protected $fillable = [
        'sale_id',
        'item_id',
        'quantity',
        'unit_price',
        'discount',
        'subtotal',
        'batch_number',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
