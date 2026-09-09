<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DsrProfile extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'dsr_profiles';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'rep_code',
        'branch_id',
        'assigned_route_id',
        'basic_salary',
        'bike_allowance',
        'fuel_allowance',
        'dsr_status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'bike_allowance' => 'decimal:2',
            'fuel_allowance' => 'decimal:2',
        ];
    }

    /**
     * Get the user that owns the DSR profile.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the branch associated with the DSR rep.
     */
    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
