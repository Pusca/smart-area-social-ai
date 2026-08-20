<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Isolamento multi-tenant: ogni query fatta nel contesto di un utente
 * autenticato è filtrata automaticamente sul suo tenant, e ogni record
 * creato eredita il tenant dell'utente. I job in coda (senza utente
 * autenticato) non vengono filtrati e devono filtrare esplicitamente.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $user = Auth::user();
            if ($user && $user->tenant_id) {
                $query->where($query->getModel()->getTable() . '.tenant_id', $user->tenant_id);
            }
        });

        static::creating(function (Model $model) {
            $user = Auth::user();
            if (empty($model->tenant_id) && $user && $user->tenant_id) {
                $model->tenant_id = $user->tenant_id;
            }
        });
    }
}
