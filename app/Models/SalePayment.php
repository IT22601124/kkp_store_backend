<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SalePayment extends Model
{
    use HasFactory;

    protected $table = 'sale_payments';

    protected $fillable = [
        'sale_id',
        'bank_account_id',
        'payment_type',
        'amount',
        'reference_number',
        'payment_date',
        'notes',
        'slip_image',
        'status',
        'verified_by',
        'verified_at',
        'rejection_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'datetime',
        'verified_at' => 'datetime',
    ];

    protected $appends = [
        'slip_url',
        'bank_slip_url',
    ];

    public function getSlipUrlAttribute(): ?string
    {
        if (!$this->slip_image) {
            return null;
        }

        if (str_starts_with($this->slip_image, 'http://') || str_starts_with($this->slip_image, 'https://')) {
            return $this->slip_image;
        }

        return Storage::disk('public')->url($this->slip_image);
    }

    public function getBankSlipUrlAttribute(): ?string
    {
        return $this->getSlipUrlAttribute();
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}

