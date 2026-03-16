<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Tenant extends Model
{
    protected static ?bool $limitsColumnAvailable = null;

    protected $fillable = ['name', 'slug', 'plan', 'is_active', 'limits'];

    protected $casts = [
        'is_active' => 'boolean',
        'limits' => 'array',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public static function supportsLimitsColumn(): bool
    {
        if (static::$limitsColumnAvailable !== null) {
            return static::$limitsColumnAvailable;
        }

        static::$limitsColumnAvailable = Schema::hasColumn((new static())->getTable(), 'limits');

        return static::$limitsColumnAvailable;
    }

    public static function flushSchemaSupportCache(): void
    {
        static::$limitsColumnAvailable = null;
    }
}
