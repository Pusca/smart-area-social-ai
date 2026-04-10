<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rappresenta l'appartenenza di un utente a un tenant (brand).
 *
 * Usato dal feature multi-brand per permettere ad agenzie e freelance
 * di gestire più clienti dallo stesso account utente.
 *
 * @property int    $user_id
 * @property int    $tenant_id
 * @property string $role      owner | editor | viewer
 */
class UserTenantMembership extends Model
{
    /**
     * Chiave primaria composta — non autoincrement.
     * Eloquent usa di default 'id', quindi la disabilitiamo.
     */
    public $incrementing = false;

    protected $fillable = ['user_id', 'tenant_id', 'role'];

    /** @return BelongsTo<User, UserTenantMembership> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Tenant, UserTenantMembership> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
