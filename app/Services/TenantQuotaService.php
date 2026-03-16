<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\Tenant;
use App\Models\User;
use RuntimeException;

class TenantQuotaService
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, int>
     */
    public function normalizeLimits(array $input): array
    {
        $limits = [];

        foreach (['max_users', 'max_content_items'] as $key) {
            $raw = $input[$key] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }

            $value = (int) $raw;
            if ($value > 0) {
                $limits[$key] = $value;
            }
        }

        return $limits;
    }

    public function maxUsers(?Tenant $tenant): ?int
    {
        return $this->limitValue($tenant, 'max_users');
    }

    public function maxContentItems(?Tenant $tenant): ?int
    {
        return $this->limitValue($tenant, 'max_content_items');
    }

    public function userUsage(Tenant $tenant): int
    {
        return (int) User::query()
            ->where('tenant_id', $tenant->id)
            ->count();
    }

    public function contentUsage(Tenant|int $tenant): int
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : (int) $tenant;

        return (int) ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->count();
    }

    public function assertCanAssignUser(Tenant $tenant, ?User $user = null): void
    {
        $maxUsers = $this->maxUsers($tenant);
        if ($maxUsers === null) {
            return;
        }

        if ($user && (int) $user->tenant_id === (int) $tenant->id) {
            return;
        }

        $current = $this->userUsage($tenant);
        if (($current + 1) > $maxUsers) {
            throw new RuntimeException("Il tenant {$tenant->name} ha raggiunto il limite utenti di {$maxUsers}.");
        }
    }

    public function assertCanCreateContentItems(Tenant|int $tenant, int $incomingItems = 1): void
    {
        $incomingItems = max(0, $incomingItems);
        if ($incomingItems < 1) {
            return;
        }

        $tenantModel = $tenant instanceof Tenant
            ? $tenant
            : Tenant::query()->find((int) $tenant);

        if (!$tenantModel) {
            return;
        }

        $maxContentItems = $this->maxContentItems($tenantModel);
        if ($maxContentItems === null) {
            return;
        }

        $current = $this->contentUsage($tenantModel);
        if (($current + $incomingItems) > $maxContentItems) {
            throw new RuntimeException("Il tenant {$tenantModel->name} ha raggiunto il limite contenuti di {$maxContentItems}.");
        }
    }

    /**
     * @return array<string, int|null|bool>
     */
    public function usageSummary(Tenant $tenant): array
    {
        $usersCount = $this->userUsage($tenant);
        $contentCount = $this->contentUsage($tenant);
        $maxUsers = $this->maxUsers($tenant);
        $maxContentItems = $this->maxContentItems($tenant);

        return [
            'users_count' => $usersCount,
            'content_items_count' => $contentCount,
            'max_users' => $maxUsers,
            'max_content_items' => $maxContentItems,
            'users_remaining' => $maxUsers !== null ? max(0, $maxUsers - $usersCount) : null,
            'content_items_remaining' => $maxContentItems !== null ? max(0, $maxContentItems - $contentCount) : null,
            'users_over_limit' => $maxUsers !== null && $usersCount > $maxUsers,
            'content_items_over_limit' => $maxContentItems !== null && $contentCount > $maxContentItems,
        ];
    }

    private function limitValue(?Tenant $tenant, string $key): ?int
    {
        if (!$tenant) {
            return null;
        }

        $limits = is_array($tenant->limits) ? $tenant->limits : [];
        $value = isset($limits[$key]) ? (int) $limits[$key] : 0;

        return $value > 0 ? $value : null;
    }
}
