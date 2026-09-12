<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DsrTrip extends Model
{
    use HasFactory;

    protected $table = 'dsr_trips';

    protected $fillable = [
        'referrer_id',
        'route_id',
        'trip_date',
        'starting_inventory_value',
        'issued_stock_value',
        'total_sales_value',
        'total_cash_collected',
        'total_credit_sales',
        'total_cheques_collected',
        'total_bank_deposits',
        'closing_inventory_value',
        'variance_amount',
        'status',
        'notes'
    ];

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function route()
    {
        return $this->belongsTo(DistributionRoute::class, 'route_id');
    }
}
