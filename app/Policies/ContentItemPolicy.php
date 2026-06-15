<?php

namespace App\Policies;

use App\Models\ContentItem;
use App\Models\User;

/**
 * Policy per ContentItem.
 *
 * Principio: un utente può accedere solo ai contenuti del proprio tenant.
 * Gli admin di piattaforma (isPlatformAdmin) bypassano tutti i controlli
 * per supporto e impersonation.
 *
 * Registrata in AppServiceProvider e auto-discoverable da Laravel 12.
 */
class ContentItemPolicy
{
    /**
     * Gli admin di piattaforma possono fare tutto — usato per impersonation e supporto.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        return null; // delega alle singole regole
    }

    /**
     * L'utente può vedere un contenuto solo se appartiene al suo tenant.
     */
    public function view(User $user, ContentItem $item): bool
    {
        return (int) $user->tenant_id === (int) $item->tenant_id;
    }

    /**
     * L'utente può modificare un contenuto solo se appartiene al suo tenant.
     */
    public function update(User $user, ContentItem $item): bool
    {
        return (int) $user->tenant_id === (int) $item->tenant_id;
    }

    /**
     * L'utente può eliminare un contenuto solo se appartiene al suo tenant.
     */
    public function delete(User $user, ContentItem $item): bool
    {
        return (int) $user->tenant_id === (int) $item->tenant_id;
    }

    /**
     * L'utente può generare AI solo per contenuti del suo tenant.
     */
    public function generate(User $user, ContentItem $item): bool
    {
        return (int) $user->tenant_id === (int) $item->tenant_id;
    }

    /**
     * L'utente può approvare per la pubblicazione solo se il contenuto è del suo tenant.
     */
    public function approve(User $user, ContentItem $item): bool
    {
        return (int) $user->tenant_id === (int) $item->tenant_id;
    }
}
