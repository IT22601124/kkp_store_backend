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
        'total_value',
        'status',
    ];

    protected $casts = [
        'total_value' => 'decimal:2',
    ];

    public function rep()
    {
        return $this->belongsTo(User::class, 'rep_id');
    }

    public function items()
    {
        return $this->hasMany(AcceptedRequestItem::class, 'rep_stock_id');
    }
}
