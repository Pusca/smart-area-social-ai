<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CanvaDesign extends Model
{
    protected $fillable = [
        'tenant_id',
        'content_item_id',
        'content_plan_id',
        'canva_template_mapping_id',
        'design_type',
        'canva_design_id',
        'canva_edit_url',
        'canva_view_url',
        'template_id',
        'source_mode',
        'generation_payload_json',
        'status',
        'thumbnail_url',
        'exported_asset_path',
        'exported_file_type',
        'meta',
    ];

    protected $casts = [
        'generation_payload_json' => 'array',
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function contentPlan(): BelongsTo
    {
        return $this->belongsTo(ContentPlan::class);
    }

    public function templateMapping(): BelongsTo
    {
        return $this->belongsTo(CanvaTemplateMapping::class, 'canva_template_mapping_id');
    }

    public function exportJobs(): HasMany
    {
        return $this->hasMany(CanvaExportJob::class);
    }
}
