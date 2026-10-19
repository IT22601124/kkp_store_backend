<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepNotification extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['user_id'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
