<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_code', 'request_id', 'rep_stock_id', 'request_key', 'request_hash',
        'from_branch_id',
        'to_referrer_id',
        'transfer_date',
        'status',
        'total_value',
        'notes'
    ];

    public function repStock()
    {
        return $this->belongsTo(RepStock::class, 'rep_stock_id');
    }

    public function fromBranch()
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toReferrer()
    {
        return $this->belongsTo(User::class, 'to_referrer_id');
    }

    public function items()
    {
        return $this->hasMany(StockTransferItem::class, 'stock_transfer_id');
    }
}
