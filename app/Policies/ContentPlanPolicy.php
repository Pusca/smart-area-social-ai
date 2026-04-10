<?php

namespace App\Policies;

use App\Models\ContentPlan;
use App\Models\User;

/**
 * Policy per ContentPlan.
 *
 * Garantisce che un utente possa gestire solo i piani editoriali del proprio tenant.
 */
class ContentPlanPolicy
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
     * L'utente può vedere un piano solo se appartiene al suo tenant.
     */
    public function view(User $user, ContentPlan $plan): bool
    {
        return (int) $user->tenant_id === (int) $plan->tenant_id;
    }

    /**
     * L'utente può modificare un piano solo se appartiene al suo tenant.
     */
    public function update(User $user, ContentPlan $plan): bool
    {
        return (int) $user->tenant_id === (int) $plan->tenant_id;
    }

    /**
     * L'utente può eliminare un piano solo se appartiene al suo tenant.
     */
    public function delete(User $user, ContentPlan $plan): bool
    {
        return (int) $user->tenant_id === (int) $plan->tenant_id;
    }

    /**
     * L'utente può triggerare la generazione AI di un piano solo se è del suo tenant.
     */
    public function generate(User $user, ContentPlan $plan): bool
    {
        return (int) $user->tenant_id === (int) $plan->tenant_id;
    }
}
