<?php

namespace App\Services\Social;

use App\Models\ContentItem;
use App\Models\GenerationRun;
use App\Models\SocialAccount;
use App\Models\SocialPublication;
use App\Services\AI\PublishReadinessGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocialPublishingService
{
    public function __construct(
        private readonly SocialAssetUrlService $assetUrlService,
        private readonly PublishReadinessGate $publishReadinessGate
    ) {
    }

    /**
     * @return array{scheduled:int,warnings:array<int,string>}
     */
    public function approve(ContentItem $item): array
    {
        $run = GenerationRun::query()
            ->where('content_item_id', (int) $item->id)
            ->latest('id')
            ->first();
        $gate = $this->publishReadinessGate->decideForContentItem($item, $run);
        if (!(bool) ($gate['approvable'] ?? false)) {
            $reasons = array_values(array_filter(array_merge(
                (array) ($gate['blocking_reasons'] ?? []),
                (array) ($gate['warnings'] ?? [])
            )));

            throw new \RuntimeException(
                'Pubblicazione bloccata dal publish gate: '
                . ($reasons !== [] ? implode(' ', $reasons) : 'contenuto non approvabile.')
            );
        }

        $item->status = 'approved';
        $meta = is_array($item->ai_meta) ? $item->ai_meta : [];
        $meta['publish_gate'] = $gate;
        $item->ai_meta = $meta;
        $item->save();

        return $this->syncForContentItem($item->fresh() ?? $item);
    }

    /**
     * @return array{scheduled:int,warnings:array<int,string>}
     */
    public function syncForContentItem(ContentItem $item): array
    {
        $warnings = [];
        $supportedPlatforms = collect($item->platforms())
            ->filter(fn (string $platform) => in_array($platform, ['instagram', 'facebook'], true))
            ->values()
            ->all();

        if (!in_array($item->status, ['approved', 'scheduled'], true)) {
            $this->cancelUnpublishedForItem($item, 'Contenuto non piu approvato per la pubblicazione automatica.');

            return [
                'scheduled' => 0,
                'warnings' => [],
            ];
        }

        if (!$item->scheduled_at) {
            return [
                'scheduled' => 0,
                'warnings' => ['Imposta data e ora prima di programmare la pubblicazione automatica.'],
            ];
        }

        if (empty($supportedPlatforms)) {
            return [
                'scheduled' => 0,
                'warnings' => ['Seleziona almeno Instagram o Facebook per attivare la pubblicazione automatica.'],
            ];
        }

        $caption = $this->buildCaption($item);
        $asset = null;

        try {
            $asset = $this->assetUrlService->resolveForContentItem($item);
        } catch (\Throwable $e) {
            $warnings[] = $e->getMessage();
        }

        if ($caption === '') {
            $warnings[] = 'Manca una caption pronta per la pubblicazione.';
        }

        $scheduled = 0;

        DB::transaction(function () use ($item, $supportedPlatforms, $caption, $asset, &$warnings, &$scheduled): void {
            $existingPlatforms = [];

            foreach ($supportedPlatforms as $platform) {
                $existingPlatforms[] = $platform;

                $account = $this->resolveAccountForPlatform((int) $item->tenant_id, $platform);
                if (!$account) {
                    $warnings[] = "Nessun account {$platform} collegato e attivo.";
                    $this->cancelPlatformPublication($item, $platform, 'Account social non collegato.');
                    continue;
                }

                if ($caption === '' || !is_array($asset)) {
                    $this->cancelPlatformPublication($item, $platform, 'Contenuto non pronto per la pubblicazione.');
                    continue;
                }

                SocialPublication::query()->updateOrCreate(
                    [
                        'content_item_id' => $item->id,
                        'platform' => $platform,
                    ],
                    [
                        'tenant_id' => $item->tenant_id,
                        'social_account_id' => $account->id,
                        'provider' => 'meta',
                        'status' => 'scheduled',
                        'media_type' => (string) ($asset['media_type'] ?? 'image'),
                        'caption' => $caption,
                        'media_url' => (string) ($asset['public_url'] ?? ''),
                        'scheduled_for' => $item->scheduled_at,
                        'error_message' => null,
                        'payload' => [
                            'title' => $item->title,
                            'format' => $item->format,
                            'media_path' => $asset['path'] ?? null,
                        ],
                    ]
                );

                $scheduled++;
            }

            SocialPublication::query()
                ->where('content_item_id', $item->id)
                ->whereNotIn('platform', $existingPlatforms)
                ->whereIn('status', ['scheduled', 'failed', 'cancelled'])
                ->update([
                    'status' => 'cancelled',
                    'error_message' => 'Piattaforma rimossa dal contenuto.',
                ]);
        });

        $item->refresh();
        $this->refreshContentItemStatus($item);

        return [
            'scheduled' => $scheduled,
            'warnings' => array_values(array_unique(array_filter($warnings))),
        ];
    }

    public function refreshContentItemStatus(ContentItem $item): void
    {
        $stats = SocialPublication::query()
            ->where('content_item_id', $item->id)
            ->selectRaw("SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published_total")
            ->selectRaw("SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_total")
            ->selectRaw("SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_total")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_total")
            ->selectRaw("SUM(CASE WHEN status != 'cancelled' THEN 1 ELSE 0 END) as active_total")
            ->first();

        $published = (int) ($stats?->published_total ?? 0);
        $scheduled = (int) ($stats?->scheduled_total ?? 0);
        $processing = (int) ($stats?->processing_total ?? 0);
        $failed = (int) ($stats?->failed_total ?? 0);
        $active = (int) ($stats?->active_total ?? 0);

        if ($active === 0) {
            if (!in_array($item->status, ['draft', 'review', 'approved'], true)) {
                $item->status = 'approved';
                $item->save();
            }

            return;
        }

        if ($published === $active) {
            $item->status = 'published';
            $item->published_at = Carbon::now();
            $item->save();
            return;
        }

        if (($scheduled + $processing) > 0) {
            $item->status = 'scheduled';
            $item->save();
            return;
        }

        if ($failed > 0 && $published === 0) {
            $item->status = 'failed';
            $item->save();
        }
    }

    public function cancelUnpublishedForItem(ContentItem $item, string $reason): void
    {
        SocialPublication::query()
            ->where('content_item_id', $item->id)
            ->whereIn('status', ['scheduled', 'processing', 'failed'])
            ->update([
                'status' => 'cancelled',
                'error_message' => $reason,
            ]);
    }

    private function cancelPlatformPublication(ContentItem $item, string $platform, string $reason): void
    {
        SocialPublication::query()
            ->where('content_item_id', $item->id)
            ->where('platform', $platform)
            ->whereIn('status', ['scheduled', 'failed'])
            ->update([
                'status' => 'cancelled',
                'error_message' => $reason,
            ]);
    }

    private function resolveAccountForPlatform(int $tenantId, string $platform): ?SocialAccount
    {
        return SocialAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', 'meta')
            ->where('platform', $platform)
            ->where('status', 'active')
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->first();
    }

    private function buildCaption(ContentItem $item): string
    {
        $parts = [];

        $caption = trim((string) ($item->ai_caption ?: $item->caption ?: ''));
        if ($caption !== '') {
            $parts[] = $caption;
        }

        $cta = trim((string) ($item->ai_cta ?? ''));
        if ($cta !== '') {
            $parts[] = $cta;
        }

        $hashtags = is_array($item->ai_hashtags) && !empty($item->ai_hashtags)
            ? $item->ai_hashtags
            : (is_array($item->hashtags) ? $item->hashtags : []);

        $hashtagsLine = collect($hashtags)
            ->map(fn ($tag) => trim((string) $tag))
            ->filter(fn (string $tag) => $tag !== '')
            ->map(fn (string $tag) => Str::startsWith($tag, '#') ? $tag : '#' . ltrim($tag, '#'))
            ->unique()
            ->implode(' ');

        if ($hashtagsLine !== '') {
            $parts[] = $hashtagsLine;
        }

        return trim(implode("\n\n", array_filter($parts)));
    }
}
