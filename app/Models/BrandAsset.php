<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BrandAsset extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'content_plan_id',
        'kind',
        'path',
        'original_name',
        'size',
        'mime',
    ];
}
