<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankFundTransfer extends Model
{
    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2'];

    public function fromAccount()
    {
        return $this->belongsTo(BankAccount::class, 'from_bank_account_id');
    }

    public function toAccount()
    {
        return $this->belongsTo(BankAccount::class, 'to_bank_account_id');
    }
}
