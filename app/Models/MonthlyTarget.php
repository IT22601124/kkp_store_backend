<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MonthlyTarget extends Model
{
    use HasFactory;

    protected $table = 'targets';

    protected $fillable = [
        'referrer_id',
        'target_month',
        'target_amount',
        'achieved_amount',
        'target_units',
        'achieved_units',
        'commission_rate_percent',
        'status'
    ];

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }
}
