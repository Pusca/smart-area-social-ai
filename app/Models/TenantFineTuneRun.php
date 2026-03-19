<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantFineTuneRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'dataset_stats' => 'array',
        'request_meta' => 'array',
        'result_meta' => 'array',
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'activated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}