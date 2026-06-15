<?php

namespace App\Services\Social;

use App\Models\ContentItem;
use App\Models\GenerationRun;
use App\Models\SocialAccount;
use App\Models\SocialPublication;
use App\Services\AI\PublishReadinessGate;
use App\Services\CaptionFormatterService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocialPublishingService
{
    public function __construct(
        private readonly SocialAssetUrlService $assetUrlService,
        private readonly PublishReadinessGate $publishReadinessGate,
        private readonly CaptionFormatterService $captionFormatter
    ) {
    }

    /**
     * Approva un contenuto per la pubblicazione dopo aver passato il publish gate.
     *
     * Il gate ha tre livelli di decisione:
     *   - pass / pass_with_warnings  → approvabile normalmente
     *   - manual_review_required     → approvabile solo con $forceApprove = true (admin override)
     *   - blocked / regenerate_*     → mai approvabile, richiede rigenerazione
     *
     * @param  bool  $forceApprove  Consente all'admin di superare il livello manual_review_required.
     * @return array{scheduled:int,warnings:array<int,string>}
     */
    public function approve(ContentItem $item, bool $forceApprove = false): array
    {
        $run = GenerationRun::query()
            ->where('content_item_id', (int) $item->id)
            ->latest('id')
            ->first();

        $gate     = $this->publishReadinessGate->decideForContentItem($item, $run);
        $decision = (string) ($gate['decision'] ?? 'blocked');
        $approvable = (bool) ($gate['approvable'] ?? false);

        // Override admin: permette di approvare contenuti in manual_review_required.
        // I contenuti con decision "blocked" o "regenerate_*" non sono mai override-abili.
        if (!$approvable && $forceApprove && $decision === 'manual_review_required') {
            $approvable = true;
        }

        if (!$approvable) {
            $reasons = array_values(array_filter(array_merge(
                (array) ($gate['blocking_reasons'] ?? []),
                (array) ($gate['warnings'] ?? [])
            )));

            // Messaggio specifico per livello di decisione, più actionable per l'utente.
            $baseMessage = match ($decision) {
                'blocked'                => 'Contenuto bloccato: non supera i controlli di qualità o identità.',
                'regenerate_visual'      => 'Il visual non è pronto: rigenera l\'immagine o il video prima di approvare.',
                'regenerate_audio'       => 'L\'audio non è pronto: rigenera il voiceover prima di approvare.',
                'regenerate_caption'     => 'La caption non è pronta: rigenera il copy prima di approvare.',
                'manual_review_required' => 'Il contenuto richiede revisione manuale. Un admin può forzare l\'approvazione.',
                default                  => 'Contenuto non approvabile al momento.',
            };

            throw new \RuntimeException(
                $baseMessage . ($reasons !== [] ? ' — ' . implode(' ', $reasons) : '')
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
    /**
     * Piattaforme supportate per la pubblicazione automatica.
     * Per aggiungere una nuova piattaforma: aggiungerla qui + creare il suo adapter.
     */
    private const SUPPORTED_PLATFORMS = [
        'instagram',
        'facebook',
        'linkedin',
        'tiktok',
        'google_business',
    ];

    public function syncForContentItem(ContentItem $item): array
    {
        $warnings = [];
        $supportedPlatforms = collect($item->platforms())
            ->filter(fn (string $platform) => in_array($platform, self::SUPPORTED_PLATFORMS, true))
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

        $asset = null;

        try {
            $asset = $this->assetUrlService->resolveForContentItem($item);
        } catch (\Throwable $e) {
            $warnings[] = $e->getMessage();
        }

        $scheduled = 0;

        DB::transaction(function () use ($item, $supportedPlatforms, $asset, &$warnings, &$scheduled): void {
            $existingPlatforms = [];

            foreach ($supportedPlatforms as $platform) {
                $existingPlatforms[] = $platform;

                // Formatta la caption seguendo le regole specifiche della piattaforma target.
                // Ogni piattaforma ha limiti diversi di caratteri, hashtag e struttura.
                $caption = $this->buildCaption($item, $platform);

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

                // Determina media_type: per caroselli Instagram usa 'carousel'
                $mediaType = (string) ($asset['media_type'] ?? 'image');
                if (
                    $platform === 'instagram'
                    && ($item->format === 'carousel'
                        || !empty(data_get(is_array($item->ai_meta) ? $item->ai_meta : [], 'carousel_images')))
                ) {
                    $mediaType = 'carousel';
                }

                SocialPublication::query()->updateOrCreate(
                    [
                        'content_item_id' => $item->id,
                        'platform'        => $platform,
                    ],
                    [
                        'tenant_id'        => $item->tenant_id,
                        'social_account_id' => $account->id,
                        'provider'         => $account->provider,
                        'status'           => 'scheduled',
                        'media_type'       => $mediaType,
                        'caption'          => $caption,
                        'media_url'        => (string) ($asset['public_url'] ?? ''),
                        'scheduled_for'    => $item->scheduled_at,
                        'error_message'    => null,
                        'payload'          => [
                            'title'           => $item->title,
                            'format'          => $item->format,
                            'media_path'      => $asset['path'] ?? null,
                            // Passa le slide del carosello al publisher
                            'carousel_images' => data_get(
                                is_array($item->ai_meta) ? $item->ai_meta : [],
                                'carousel_images',
                                []
                            ),
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

    /**
     * Risolve l'account social attivo per una piattaforma.
     *
     * Ogni piattaforma ha il proprio provider:
     *   instagram / facebook → meta
     *   linkedin             → linkedin
     *   tiktok               → tiktok
     *   google_business      → google
     *
     * Se esiste un account `is_primary` lo preferisce, altrimenti prende il più recente.
     */
    private function resolveAccountForPlatform(int $tenantId, string $platform): ?SocialAccount
    {
        // Mappa piattaforma → provider API
        $providerMap = [
            'instagram'       => 'meta',
            'facebook'        => 'meta',
            'linkedin'        => 'linkedin',
            'tiktok'          => 'tiktok',
            'google_business' => 'google',
        ];

        $provider = $providerMap[$platform] ?? null;
        if ($provider === null) {
            return null;
        }

        return SocialAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', $provider)
            ->where('platform', $platform)
            ->where('status', 'active')
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Costruisce la caption finale pronta per la pubblicazione.
     *
     * Usa CaptionFormatterService per rispettare le regole di ogni piattaforma:
     * separazione hashtag, limiti caratteri, CTA come paragrafo indipendente.
     * Il platform viene derivato dalla SocialPublication in corso, non dal ContentItem,
     * perché lo stesso item può essere pubblicato su piattaforme diverse.
     */
    private function buildCaption(ContentItem $item, string $platform = 'instagram'): string
    {
        $caption  = trim((string) ($item->ai_caption ?: $item->caption ?: ''));
        $cta      = trim((string) ($item->ai_cta ?? ''));
        $hashtags = is_array($item->ai_hashtags) && !empty($item->ai_hashtags)
            ? $item->ai_hashtags
            : (is_array($item->hashtags) ? $item->hashtags : []);

        // CaptionFormatterService gestisce: spacing Instagram, limite caratteri,
        // blocco hashtag separato, normalizzazione # prefix.
        return $this->captionFormatter->format($caption, $cta, $hashtags, $platform);
    }
}
