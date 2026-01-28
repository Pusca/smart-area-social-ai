<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentPlan extends Model
{
    protected $fillable = [
        'tenant_id',
        'created_by',
        'name',
        'start_date',
        'end_date',
        'status',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
    ];
}
