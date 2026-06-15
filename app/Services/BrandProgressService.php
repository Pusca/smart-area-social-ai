<?php

namespace App\Services;

use App\Models\BrandAsset;
use App\Models\ContentItem;
use App\Models\TenantProfile;
use Illuminate\Support\Carbon;

/**
 * Calcola il "Brand Progress Score": uno score multi-dimensionale (0–100)
 * che misura quanto il profilo brand di un tenant è completo, attivo e
 * di qualità, così che il dashboard possa mostrare un indicatore utile
 * e suggerire le prossime azioni di miglioramento.
 *
 * Dimensioni:
 *   1. profile_completeness   → campi del TenantProfile compilati
 *   2. asset_richness         → asset brand caricati (logo, immagini, video)
 *   3. content_quality        → scorecard media degli ultimi contenuti AI
 *   4. publishing_consistency → % di contenuti pubblicati negli ultimi 30 gg
 *   5. learning_richness      → ricchezza del profilo di apprendimento
 *
 * Lo score finale è la media pesata delle 5 dimensioni.
 */
class BrandProgressService
{
    /**
     * Pesi relativi di ciascuna dimensione nel calcolo dello score finale.
     * La somma deve essere 1.0.
     */
    private const DIMENSION_WEIGHTS = [
        'profile_completeness'   => 0.30,
        'asset_richness'         => 0.20,
        'content_quality'        => 0.25,
        'publishing_consistency' => 0.15,
        'learning_richness'      => 0.10,
    ];

    /**
     * Soglie per i label di qualità (sullo score 0–100).
     */
    private const LABEL_THRESHOLDS = [
        80 => ['label' => 'Eccellente',  'badge' => 'bg-emerald-100 text-emerald-700 border-emerald-200'],
        60 => ['label' => 'Buono',       'badge' => 'bg-indigo-100 text-indigo-700 border-indigo-200'],
        40 => ['label' => 'In crescita', 'badge' => 'bg-amber-100 text-amber-700 border-amber-200'],
         0 => ['label' => 'Da completare','badge' => 'bg-red-100 text-red-700 border-red-200'],
    ];

    /**
     * Calcola il brand progress per un tenant.
     *
     * @return array{
     *   overall_score: int,
     *   overall_label: string,
     *   overall_badge: string,
     *   dimensions: array<string, array{score:int,label:string,badge:string,recommendations:list<string>}>,
     *   next_actions: list<string>
     * }
     */
    public function computeForTenant(int $tenantId): array
    {
        $profile  = TenantProfile::query()->where('tenant_id', $tenantId)->first();
        $assets   = BrandAsset::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('content_plan_id')
            ->get();

        // Ultimi 30 contenuti generati per la qualità
        $recentItems = ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->where('ai_status', 'done')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        // Contenuti degli ultimi 30 giorni per la consistenza
        $since = Carbon::now()->subDays(30);
        $recentWindow = ContentItem::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $since)
            ->selectRaw("COUNT(*) as total")
            ->selectRaw("SUM(CASE WHEN status = 'published' OR published_at IS NOT NULL THEN 1 ELSE 0 END) as published")
            ->first();

        // ── Calcola le 5 dimensioni ──────────────────────────────────────────

        $dimensions = [
            'profile_completeness'   => $this->scoreProfileCompleteness($profile),
            'asset_richness'         => $this->scoreAssetRichness($assets),
            'content_quality'        => $this->scoreContentQuality($recentItems),
            'publishing_consistency' => $this->scorePublishingConsistency($recentWindow),
            'learning_richness'      => $this->scoreLearningRichness($profile),
        ];

        // ── Score complessivo pesato ──────────────────────────────────────────
        $overall = 0.0;
        foreach ($dimensions as $key => $dim) {
            $overall += $dim['score'] * self::DIMENSION_WEIGHTS[$key];
        }
        $overallScore = (int) round($overall);

        $overallMeta  = $this->labelFor($overallScore);

        // ── Prossime azioni prioritarie (max 3, dalla dimensione più debole) ──
        $nextActions = $this->extractTopActions($dimensions);

        return [
            'overall_score' => $overallScore,
            'overall_label' => $overallMeta['label'],
            'overall_badge' => $overallMeta['badge'],
            'dimensions'    => $dimensions,
            'next_actions'  => $nextActions,
        ];
    }

    // ─── Scoring per dimensione ───────────────────────────────────────────────

    /**
     * Misura quanto è compilato il TenantProfile.
     * Ogni campo ha un peso: i campi core valgono di più.
     *
     * @param  TenantProfile|null  $profile
     */
    private function scoreProfileCompleteness(?TenantProfile $profile): array
    {
        if (!$profile) {
            return $this->dimension(0, [
                'Crea il profilo brand dal Brand Center per iniziare.',
            ]);
        }

        // campo → peso (totale = 100)
        $fields = [
            'business_name' => 20,
            'industry'      => 15,
            'services'      => 15,
            'target'        => 15,
            'cta'           => 10,
            'website'       => 10,
            'vision'        => 5,
            'values'        => 5,
            'notes'         => 5,
        ];

        $earned = 0;
        $missing = [];

        foreach ($fields as $field => $weight) {
            $value = $profile->{$field};
            $filled = is_array($value)
                ? !empty($value)
                : (trim((string) ($value ?? '')) !== '');

            if ($filled) {
                $earned += $weight;
            } else {
                $missing[] = $field;
            }
        }

        $recommendations = [];
        foreach (array_slice($missing, 0, 2) as $f) {
            $recommendations[] = 'Compila il campo "' . str_replace('_', ' ', $f) . '" nel Brand Center.';
        }

        return $this->dimension($earned, $recommendations);
    }

    /**
     * Misura la ricchezza degli asset brand caricati (logo, immagini, video).
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $assets
     */
    private function scoreAssetRichness($assets): array
    {
        $hasLogo    = $assets->contains('kind', 'logo');
        $imageCount = $assets->where('kind', 'image')->count();
        $hasVideo   = $assets->contains('kind', 'video');

        // Punteggio: logo 30 pt, immagini max 50 pt (10 pt ciascuna fino a 5), video 20 pt
        $score = 0;
        $recs  = [];

        if ($hasLogo) {
            $score += 30;
        } else {
            $recs[] = 'Carica il logo del brand (PNG trasparente preferito).';
        }

        $imageScore = min(50, $imageCount * 10);
        $score += $imageScore;
        if ($imageCount < 5) {
            $recs[] = 'Carica almeno ' . (5 - $imageCount) . ' immagini brand per raggiungere la soglia ottimale.';
        }

        if ($hasVideo) {
            $score += 20;
        } else {
            $recs[] = 'Aggiungi un video/reel del brand per attivare contenuti video.';
        }

        return $this->dimension($score, $recs);
    }

    /**
     * Misura la qualità media dei contenuti AI generati di recente,
     * leggendo il campo `ai_meta.scorecard.overall_score` quando presente.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $recentItems
     */
    private function scoreContentQuality($recentItems): array
    {
        if ($recentItems->isEmpty()) {
            return $this->dimension(0, [
                'Genera il tuo primo piano editoriale per popolare lo score di qualità.',
            ]);
        }

        $scores = [];
        foreach ($recentItems as $item) {
            $meta = is_array($item->ai_meta) ? $item->ai_meta : (array) ($item->ai_meta ?? []);
            $overall = data_get($meta, 'scorecard.overall_score');
            if (is_numeric($overall)) {
                // scorecard è 0–1, convertiamo in 0–100
                $scores[] = (float) $overall * 100;
            }
        }

        if (empty($scores)) {
            // Contenuti presenti ma senza scorecard: assume punteggio neutro
            return $this->dimension(55, [
                'Abilita il Quality Scorecard per misurare la qualità dei contenuti generati.',
            ]);
        }

        $avg  = array_sum($scores) / count($scores);
        $recs = [];

        if ($avg < 60) {
            $recs[] = 'La qualità media dei contenuti è bassa. Arricchisci il profilo brand e le immagini.';
        } elseif ($avg < 75) {
            $recs[] = 'Aggiungi più esempi di contenuti approvati per migliorare l\'apprendimento AI.';
        }

        return $this->dimension((int) round($avg), $recs);
    }

    /**
     * Misura la costanza di pubblicazione (% di contenuti pubblicati negli ultimi 30 giorni).
     *
     * @param  object|null  $recentWindow  → risultato query aggregata con ->total e ->published
     */
    private function scorePublishingConsistency(?object $recentWindow): array
    {
        $total     = (int) ($recentWindow->total ?? 0);
        $published = (int) ($recentWindow->published ?? 0);

        if ($total === 0) {
            return $this->dimension(0, [
                'Nessun contenuto negli ultimi 30 giorni. Crea e pubblica un piano editoriale.',
            ]);
        }

        $ratio = $published / $total;
        $score = (int) round($ratio * 100);

        $recs = [];
        if ($ratio < 0.5) {
            $recs[] = 'Solo ' . $published . ' su ' . $total . ' contenuti sono stati pubblicati. Approva e pubblica i contenuti in coda.';
        } elseif ($ratio < 0.8) {
            $recs[] = 'Pubblica i contenuti rimasti in bozza per mantenere la costanza editoriale.';
        }

        return $this->dimension($score, $recs);
    }

    /**
     * Misura la ricchezza del profilo di apprendimento AI (learning_preferences).
     *
     * @param  TenantProfile|null  $profile
     */
    private function scoreLearningRichness(?TenantProfile $profile): array
    {
        if (!$profile) {
            return $this->dimension(0, []);
        }

        $learning = is_array($profile->learning_preferences) ? $profile->learning_preferences : [];

        if (empty($learning)) {
            return $this->dimension(10, [
                'Lascia feedback sui contenuti generati per addestrare il motore AI sul tuo stile.',
            ]);
        }

        // Campi attesi nel learning profile
        $keys = [
            'preferred_topics', 'avoided_topics', 'tone_feedback',
            'top_performing_formats', 'low_performing_formats',
            'best_hashtag_groups', 'posting_time_insights',
        ];

        $filled = 0;
        foreach ($keys as $key) {
            $val = $learning[$key] ?? null;
            if (is_array($val) ? !empty($val) : trim((string) ($val ?? '')) !== '') {
                $filled++;
            }
        }

        $score = (int) round(($filled / count($keys)) * 100);

        $recs = [];
        if ($score < 50) {
            $recs[] = 'Continua a valutare i contenuti: il profilo di apprendimento è ancora parziale.';
        }

        return $this->dimension($score, $recs);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    /**
     * Costruisce la struttura standardizzata di una dimensione.
     *
     * @param  list<string>  $recommendations
     * @return array{score:int,label:string,badge:string,recommendations:list<string>}
     */
    private function dimension(int $score, array $recommendations): array
    {
        $meta = $this->labelFor($score);

        return [
            'score'           => max(0, min(100, $score)),
            'label'           => $meta['label'],
            'badge'           => $meta['badge'],
            'recommendations' => array_values($recommendations),
        ];
    }

    /**
     * Restituisce label e classe CSS badge per un dato score.
     *
     * @return array{label:string,badge:string}
     */
    private function labelFor(int $score): array
    {
        foreach (self::LABEL_THRESHOLDS as $threshold => $meta) {
            if ($score >= $threshold) {
                return $meta;
            }
        }

        return self::LABEL_THRESHOLDS[0];
    }

    /**
     * Estrae le top 3 azioni raccomandate dalle dimensioni con score più basso.
     *
     * @param  array<string, array{score:int,recommendations:list<string>}>  $dimensions
     * @return list<string>
     */
    private function extractTopActions(array $dimensions): array
    {
        // Ordina le dimensioni per score crescente (le più deboli prima)
        uasort($dimensions, fn ($a, $b) => $a['score'] <=> $b['score']);

        $actions = [];
        foreach ($dimensions as $dim) {
            foreach ($dim['recommendations'] as $rec) {
                $actions[] = $rec;
                if (count($actions) >= 3) {
                    break 2;
                }
            }
        }

        return $actions;
    }
}
