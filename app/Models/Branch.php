<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'address',
        'total_warehouse_stock_value',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_warehouse_stock_value' => 'decimal:2',
        ];
    }

    /**
     * Get the stocks associated with the branch.
     */
    public function stocks()
    {
        return $this->hasMany(Stock::class, 'branch_id');
    }
}
