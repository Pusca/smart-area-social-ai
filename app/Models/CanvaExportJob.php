<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanvaExportJob extends Model
{
    protected $fillable = [
        'tenant_id',
        'canva_design_id',
        'external_job_id',
        'export_type',
        'status',
        'download_url',
        'completed_at',
        'stored_path',
        'metadata_json',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'metadata_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function design(): BelongsTo
    {
        return $this->belongsTo(CanvaDesign::class, 'canva_design_id');
    }
}
