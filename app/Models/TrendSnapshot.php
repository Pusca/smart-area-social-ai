<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrendSnapshot extends Model
{
    protected $fillable = [
        'tenant_id',
        'status',
        'source_type',
        'version',
        'summary',
        'opportunity_summary',
        'meta',
        'observed_at',
    ];

    protected $casts = [
        'summary' => 'array',
        'opportunity_summary' => 'array',
        'meta' => 'array',
        'observed_at' => 'datetime',
    ];

    public function signals(): HasMany
    {
        return $this->hasMany(TrendSignal::class, 'trend_snapshot_id');
    }
}