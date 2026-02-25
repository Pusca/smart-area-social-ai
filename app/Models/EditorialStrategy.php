<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EditorialStrategy extends Model
{
    protected $fillable = [
        'tenant_id',
        'brand_voice',
        'pillars',
        'rubrics',
        'cta_rules',
        'constraints',
        'last_refreshed_at',
    ];

    protected $casts = [
        'brand_voice' => 'array',
        'pillars' => 'array',
        'rubrics' => 'array',
        'cta_rules' => 'array',
        'constraints' => 'array',
        'last_refreshed_at' => 'datetime',
    ];
}

