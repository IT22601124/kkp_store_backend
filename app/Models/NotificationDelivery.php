<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['device_token'];
}
