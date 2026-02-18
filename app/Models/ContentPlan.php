<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'strategy',
    ];

    protected $casts = [
        'settings'   => 'array',
        'strategy'   => 'array',
        'start_date' => 'datetime',
        'end_date'   => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'content_plan_id');
    }
}
