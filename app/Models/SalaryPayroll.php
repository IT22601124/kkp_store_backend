<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalaryPayroll extends Model
{
    use HasFactory;

    protected $table = 'payrolls';

    protected $fillable = [
        'referrer_id',
        'pay_period',
        'basic_salary',
        'bike_allowance',
        'fuel_allowance',
        'earned_commission',
        'total_deductions',
        'net_payable',
        'payment_status'
    ];

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }
}
