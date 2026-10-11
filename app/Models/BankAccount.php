<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    use HasFactory;

    protected $table = 'bank_accounts';

    protected $fillable = [
        'user_id',
        'bank_name',
        'account_number',
        'account_name',
        'branch_name',
        'account_type',
        'currency',
        'opening_balance',
        'current_balance',
        'is_default',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function salePayments()
    {
        return $this->hasMany(SalePayment::class, 'bank_account_id');
    }

    public function dsrBankDeposits()
    {
        return $this->hasMany(DsrBankDeposit::class, 'bank_account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
