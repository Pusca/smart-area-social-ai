<?php

namespace App\Services\Notification;

use App\Models\User;
use App\Notifications\WorkspaceEventNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class WorkspaceNotificationService
{
    public function __construct(
        private readonly WebPushNotificationService $webPushNotifications
    ) {
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function notifyTenant(int $tenantId, string $title, string $body, array $options = []): int
    {
        $users = User::query()
            ->where('tenant_id', $tenantId)
            ->get();

        return $this->notifyUsers($users, $title, $body, $options);
    }

    /**
     * @param  iterable<int, User>  $users
     * @param  array<string, mixed>  $options
     */
    public function notifyUsers(iterable $users, string $title, string $body, array $options = []): int
    {
        $collection = $users instanceof Collection ? $users->values() : collect($users)->values();
        if ($collection->isEmpty()) {
            return 0;
        }

        NotificationFacade::send($collection, new WorkspaceEventNotification($title, $body, $options));
        $this->webPushNotifications->notifyUsers($collection, $title, $body, $options);

        return $collection->count();
    }
}
