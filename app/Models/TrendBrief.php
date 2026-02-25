<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrendBrief extends Model
{
    protected $fillable = [
        'tenant_id',
        'snapshot',
        'fetched_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'fetched_at' => 'datetime',
    ];
}

