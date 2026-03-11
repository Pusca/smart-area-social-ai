<?php

namespace App\Jobs;

use App\Models\SocialPublication;
use App\Services\Notification\WorkspaceNotificationService;
use App\Services\Social\MetaGraphService;
use App\Services\Social\SocialPublishingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class PublishSocialPublication implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(
        public int $socialPublicationId
    ) {
        $this->onQueue((string) config('meta.publish_queue', 'social-publish'));
    }

    public function handle(
        MetaGraphService $metaGraphService,
        SocialPublishingService $socialPublishingService,
        WorkspaceNotificationService $workspaceNotifications
    ): void
    {
        $publication = SocialPublication::query()
            ->with(['contentItem', 'socialAccount'])
            ->find($this->socialPublicationId);

        if (!$publication || !$publication->contentItem || !$publication->socialAccount) {
            return;
        }

        if (!in_array($publication->status, ['scheduled', 'failed', 'processing'], true)) {
            return;
        }

        if ($publication->scheduled_for && $publication->scheduled_for->isFuture()) {
            return;
        }

        $publication->status = 'processing';
        $publication->attempts = (int) $publication->attempts + 1;
        $publication->last_attempt_at = Carbon::now();
        $publication->error_message = null;
        $publication->save();

        try {
            $result = $metaGraphService->publish($publication->socialAccount, $publication);

            $publication->status = 'published';
            $publication->published_at = Carbon::now();
            $publication->remote_id = trim((string) ($result['remote_id'] ?? '')) ?: null;
            $publication->remote_url = trim((string) ($result['remote_url'] ?? '')) ?: null;
            $publication->response_meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
            $publication->error_message = null;
            $publication->save();
            $workspaceNotifications->notifyTenant(
                (int) $publication->tenant_id,
                'Pubblicazione completata',
                $this->publicationLabel($publication) . ' e stato pubblicato su ' . ucfirst((string) $publication->platform) . '.',
                [
                    'level' => 'success',
                    'icon' => 'publish-done',
                    'action_url' => route('calendar'),
                    'action_label' => 'Apri calendario',
                    'context_type' => 'social_publication',
                    'context_id' => (int) $publication->id,
                ]
            );
        } catch (\Throwable $e) {
            $publication->status = 'failed';
            $publication->error_message = $e->getMessage();
            $publication->response_meta = [
                'error' => $e->getMessage(),
                'failed_at' => Carbon::now()->toDateTimeString(),
            ];
            $publication->save();
            $workspaceNotifications->notifyTenant(
                (int) $publication->tenant_id,
                'Pubblicazione fallita',
                $this->publicationLabel($publication) . ' non e stato pubblicato su ' . ucfirst((string) $publication->platform) . '.',
                [
                    'level' => 'error',
                    'icon' => 'publish-error',
                    'action_url' => route('posts.edit', $publication->contentItem),
                    'action_label' => 'Apri contenuto',
                    'context_type' => 'social_publication',
                    'context_id' => (int) $publication->id,
                    'meta' => [
                        'error' => $e->getMessage(),
                    ],
                ]
            );
        }

        $socialPublishingService->refreshContentItemStatus($publication->contentItem->fresh() ?? $publication->contentItem);
    }

    private function publicationLabel(SocialPublication $publication): string
    {
        $title = trim((string) ($publication->contentItem?->title ?: $publication->contentItem?->content_angle ?: 'Contenuto'));

        return '"' . str($title)->limit(70, '')->toString() . '"';
    }
}
