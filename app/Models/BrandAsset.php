<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrandAsset extends Model
{
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
