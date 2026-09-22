<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RepStock extends Model
{
    use HasFactory;

    protected $table = 'rep_stocks';

    protected $fillable = [
        'rep_id',
        'branch_id',
        'item_id',
        'quantity',
        'reserved_quantity',
        'unit_cost',
        'unit_price',
        'total_value',
        'batch_number',
        'status',
        'last_synced_at',
        'last_audited_at'
    ];

    protected $casts = [
        'quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total_value' => 'decimal:2',
        'last_synced_at' => 'datetime',
        'last_audited_at' => 'datetime'
    ];

    public function rep()
    {
        return $this->belongsTo(User::class, 'rep_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
