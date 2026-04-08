<?php

namespace App\Services\Notification;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushNotificationService
{
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

        $publicKey = trim((string) env('VAPID_PUBLIC_KEY', ''));
        $privateKey = trim((string) env('VAPID_PRIVATE_KEY', ''));
        if ($publicKey === '' || $privateKey === '') {
            return 0;
        }

        $subscriptions = PushSubscription::query()
            ->whereIn('user_id', $collection->pluck('id')->map(fn ($id) => (int) $id)->all())
            ->get();

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        try {
            $opensslConf = env('OPENSSL_CONF');
            if (!empty($opensslConf)) {
                putenv("OPENSSL_CONF={$opensslConf}");
            }
            $opensslModules = env('OPENSSL_MODULES');
            if (!empty($opensslModules)) {
                putenv("OPENSSL_MODULES={$opensslModules}");
            }

            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => env('VAPID_SUBJECT', 'mailto:info@smartera.com'),
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ]);
            $webPush->setReuseVAPIDHeaders(true);

            $payload = json_encode([
                'title' => trim($title),
                'body' => trim($body),
                'tag' => trim((string) ($options['tag'] ?? 'workspace-event')),
                'url' => trim((string) ($options['action_url'] ?? '/dashboard')) ?: '/dashboard',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            foreach ($subscriptions as $subscription) {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => (string) $subscription->endpoint,
                        'publicKey' => (string) $subscription->p256dh,
                        'authToken' => (string) $subscription->auth,
                        'contentEncoding' => (string) ($subscription->content_encoding ?: 'aes128gcm'),
                    ]),
                    $payload
                );
            }

            $sent = 0;
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $sent++;
                    continue;
                }

                $reason = strtolower((string) $report->getReason());
                if (str_contains($reason, '410') || str_contains($reason, '404')) {
                    PushSubscription::query()
                        ->where('endpoint', (string) $report->getEndpoint())
                        ->delete();
                }
            }

            return $sent;
        } catch (\Throwable $e) {
            Log::warning('Web push notification delivery failed', [
                'error' => $e->getMessage(),
                'subscription_count' => $subscriptions->count(),
            ]);

            return 0;
        }
    }
}
