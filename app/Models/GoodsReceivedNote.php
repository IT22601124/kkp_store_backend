<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceivedNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'grn_number',
        'branch_id',
        'supplier_invoice_no',
        'received_date',
        'received_by',
        'total_value',
        'status',
        'notes'
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function items()
    {
        return $this->hasMany(GrnItem::class, 'goods_received_note_id');
    }
}
