<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_code',
        'rep_id',
        'branch_id',
        'status',
        'notes',
    ];

    public function rep()
    {
        return $this->belongsTo(User::class, 'rep_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function items()
    {
        return $this->hasMany(StockRequestItem::class);
    }
}
