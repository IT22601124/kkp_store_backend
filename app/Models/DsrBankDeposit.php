<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DsrBankDeposit extends Model
{
    use HasFactory;

    protected $table = 'dsr_bank_deposits';

    protected $fillable = [
        'bank_account_id',
        'rep_id',
        'dsr_trip_id',
        'daily_settlement_id',
        'deposit_date',
        'amount',
        'slip_reference',
        'deposit_slip_image',
        'status',
        'notes',
    ];

    protected $casts = [
        'deposit_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function rep()
    {
        return $this->belongsTo(User::class, 'rep_id');
    }

    public function dsrTrip()
    {
        return $this->belongsTo(DsrTrip::class, 'dsr_trip_id');
    }

    public function dailySettlement()
    {
        return $this->belongsTo(DailySettlement::class, 'daily_settlement_id');
    }
}
