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
        'fuel_advances', 'epf_etf_deduction', 'commission_breakdown', 'payment_date',
        'payment_status'
    ];

    protected $casts = ['commission_breakdown' => 'array', 'payment_date' => 'date'];

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }
}
