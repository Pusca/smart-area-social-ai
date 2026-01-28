<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'endpoint',
        'p256dh',
        'auth',
        'content_encoding',
    ];
}
