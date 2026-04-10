<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Restituisce tutte le membership multi-brand dell'utente.
     * Usato dal feature agenzia per listare i brand accessibili.
     *
     * @return HasMany<UserTenantMembership>
     */
    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(UserTenantMembership::class);
    }

    public function hasPlatformAdminRole(): bool
    {
        $role = strtolower(trim((string) ($this->role ?? '')));

        return in_array($role, ['admin', 'super_admin'], true);
    }

    public function isPlatformAdmin(): bool
    {
        if (!$this->hasPlatformAdminRole()) {
            return false;
        }

        $email = strtolower(trim((string) ($this->email ?? '')));
        if ($email === '') {
            return false;
        }

        $authorizedEmails = array_values(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            (array) config('platform_admin.authorized_emails', [])
        )));

        if (empty($authorizedEmails)) {
            return true;
        }

        return in_array($email, $authorizedEmails, true);
    }
}
