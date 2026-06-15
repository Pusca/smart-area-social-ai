<?php

namespace App\Policies;

use App\Models\BrandAsset;
use App\Models\User;

/**
 * Policy per BrandAsset.
 *
 * Garantisce che un utente possa gestire solo gli asset del proprio tenant.
 * Critico per la sicurezza: senza questa policy un utente con tenant_id
 * manipolato nel form potrebbe accedere a loghi e immagini di altri brand.
 */
class BrandAssetPolicy
{
    /**
     * Gli admin di piattaforma bypassano tutti i controlli.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * L'utente può vedere un asset solo se appartiene al suo tenant.
     */
    public function view(User $user, BrandAsset $asset): bool
    {
        return (int) $user->tenant_id === (int) $asset->tenant_id;
    }

    /**
     * L'utente può modificare un asset solo se appartiene al suo tenant.
     */
    public function update(User $user, BrandAsset $asset): bool
    {
        return (int) $user->tenant_id === (int) $asset->tenant_id;
    }

    /**
     * L'utente può eliminare un asset solo se appartiene al suo tenant.
     */
    public function delete(User $user, BrandAsset $asset): bool
    {
        return (int) $user->tenant_id === (int) $asset->tenant_id;
    }
}
