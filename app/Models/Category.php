<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
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
        'badge_color',
        'status',
    ];

    /**
     * Get the items belonging to this category.
     */
    public function items()
    {
        return $this->hasMany(Item::class);
    }
}
