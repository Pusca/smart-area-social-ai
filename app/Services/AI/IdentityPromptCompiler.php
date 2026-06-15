<?php

namespace App\Services\AI;

use App\Models\IdentityAsset;
use Illuminate\Support\Str;

/**
 * Compila e mantiene aggiornato il identity_prompt di un IdentityAsset.
 *
 * Il prompt compilato è un blocco testuale strutturato, pronto per essere
 * iniettato come istruzione visiva nel pipeline AI (BuildVisualPromptStep).
 *
 * Struttura output:
 *   [IDENTITY: <type> — <name>]
 *   CARATTERISTICHE INVARIANTI: ...
 *   REGOLE DI COMPOSIZIONE: ...
 *   NON MODIFICARE: ...
 *   [IDENTITY_END]
 *
 * Il formato delimitato permette al pipeline di estrarre o sostituire
 * il blocco identità senza rompere il resto del prompt.
 */
class IdentityPromptCompiler
{
    // Etichette leggibili per tipo
    private const TYPE_LABELS = [
        IdentityAsset::TYPE_PERSON       => 'PERSONA',
        IdentityAsset::TYPE_PRODUCT      => 'PRODOTTO',
        IdentityAsset::TYPE_LOCATION     => 'LOCATION',
        IdentityAsset::TYPE_BRAND_OBJECT => 'BRAND OBJECT',
    ];

    /**
     * Restituisce il prompt compilato, usando la cache se valida.
     */
    public function compiledPrompt(IdentityAsset $identity): string
    {
        if ($identity->needsRecompile()) {
            $this->recompile($identity);
        }

        return (string) ($identity->identity_prompt ?? '');
    }

    /**
     * Rigenera e salva identity_prompt, incrementa identity_prompt_version.
     */
    public function recompile(IdentityAsset $identity): void
    {
        // Carica i media se non già eager-loaded
        if (! $identity->relationLoaded('media')) {
            $identity->load('media');
        }

        $prompt = $this->build($identity);

        $identity->identity_prompt         = $prompt;
        $identity->identity_prompt_version = ($identity->identity_prompt_version ?? 0) + 1;
        $identity->saveQuietly();
    }

    /**
     * Invalida la cache senza rigenerare (usato quando i dati correlati cambiano).
     */
    public function invalidate(IdentityAsset $identity): void
    {
        $identity->identity_prompt = null;
        $identity->saveQuietly();
    }

    /**
     * Costruisce il testo del prompt senza salvarlo.
     */
    public function build(IdentityAsset $identity): string
    {
        $typeLabel = self::TYPE_LABELS[$identity->type] ?? strtoupper($identity->type);
        $name = trim($identity->name);

        $parts = [];

        // ── Header ────────────────────────────────────────────────────────────
        $parts[] = "[IDENTITY: {$typeLabel} — {$name}]";

        // ── Descrizione ───────────────────────────────────────────────────────
        if (! empty($identity->description)) {
            $parts[] = 'SOGGETTO: ' . Str::limit(trim($identity->description), 280, '');
        }

        // ── Caratteristiche visive invarianti ─────────────────────────────────
        $features = $identity->visual_features_json ?? [];
        if (! empty($features)) {
            $featureLines = $this->renderFeatures($features, $identity->type);
            if ($featureLines !== '') {
                $parts[] = "CARATTERISTICHE INVARIANTI:\n" . $featureLines;
            }
        }

        // ── Regole di utilizzo ────────────────────────────────────────────────
        $usageRules = $identity->usage_rules_json ?? [];
        if (! empty($usageRules)) {
            $usageLines = $this->renderUsageRules($usageRules, $identity->type);
            if ($usageLines !== '') {
                $parts[] = "REGOLE DI COMPOSIZIONE:\n" . $usageLines;
            }
        }

        // ── Lock ─────────────────────────────────────────────────────────────
        if ($identity->locked_visual_identity) {
            $lockText = $this->buildLockText($identity);
            if ($lockText !== '') {
                $parts[] = "NON MODIFICARE:\n" . $lockText;
            }
        }

        // ── Media canonici ────────────────────────────────────────────────────
        $canonicalCount = $identity->canonicalMedia()->count();
        if ($canonicalCount > 0) {
            $parts[] = 'RIFERIMENTO VISIVO: usa le immagini di riferimento canoniche allegate come fonte autoritativa per l\'identità visiva di questo soggetto.';
        }

        // ── Footer ────────────────────────────────────────────────────────────
        $parts[] = '[IDENTITY_END]';

        return implode("\n\n", array_filter($parts));
    }

    // ── Rendering helpers ─────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $features
     */
    private function renderFeatures(array $features, string $type): string
    {
        $lines = [];

        // Campi comuni con label italiane leggibili
        $fieldLabels = $this->featureLabelsForType($type);

        foreach ($fieldLabels as $key => $label) {
            if (! isset($features[$key]) || $features[$key] === '' || $features[$key] === null) {
                continue;
            }
            $value = is_array($features[$key])
                ? implode(', ', array_filter(array_map('strval', $features[$key])))
                : trim((string) $features[$key]);
            if ($value !== '') {
                $lines[] = "- {$label}: {$value}";
            }
        }

        // Eventuali campi extra non mappati
        $knownKeys = array_keys($fieldLabels);
        foreach ($features as $key => $value) {
            if (in_array($key, $knownKeys, true)) {
                continue;
            }
            $value = is_array($value)
                ? implode(', ', array_filter(array_map('strval', $value)))
                : trim((string) $value);
            if ($value !== '') {
                $label = ucfirst(str_replace('_', ' ', $key));
                $lines[] = "- {$label}: {$value}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function renderUsageRules(array $rules, string $type): string
    {
        $lines = [];

        $ruleLabels = [
            'always_show_face'    => 'mostra sempre il viso chiaramente riconoscibile',
            'preferred_angle'     => 'angolo preferito',
            'avoid'               => 'evita assolutamente',
            'preferred_framing'   => 'inquadratura preferita',
            'background_style'    => 'stile sfondo',
            'lighting'            => 'illuminazione',
            'always_include'      => 'includi sempre',
            'color_palette'       => 'palette colore',
            'presentation_style'  => 'stile di presentazione',
            'perspective'         => 'prospettiva',
            'environment'         => 'ambiente',
        ];

        foreach ($ruleLabels as $key => $label) {
            if (! isset($rules[$key]) || $rules[$key] === '' || $rules[$key] === null || $rules[$key] === false) {
                continue;
            }
            if ($rules[$key] === true) {
                $lines[] = "- {$label}";
                continue;
            }
            $value = is_array($rules[$key])
                ? implode(', ', array_filter(array_map('strval', $rules[$key])))
                : trim((string) $rules[$key]);
            if ($value !== '') {
                $lines[] = "- {$label}: {$value}";
            }
        }

        // Campi extra non mappati
        $knownKeys = array_keys($ruleLabels);
        foreach ($rules as $key => $value) {
            if (in_array($key, $knownKeys, true) || in_array($key, ['min_confidence', 'blocking', 'required_features'], true)) {
                continue;
            }
            $value = is_array($value)
                ? implode(', ', array_filter(array_map('strval', $value)))
                : (is_bool($value) ? ($value ? 'sì' : 'no') : trim((string) $value));
            if ($value !== '') {
                $label = ucfirst(str_replace('_', ' ', $key));
                $lines[] = "- {$label}: {$value}";
            }
        }

        return implode("\n", $lines);
    }

    private function buildLockText(IdentityAsset $identity): string
    {
        $lines = [];
        $features = $identity->visual_features_json ?? [];
        $rules = $identity->consistency_rules_json ?? [];

        // Costruisce un riassunto dei vincoli imposti dal lock
        if (! empty($features)) {
            $locked = collect($features)
                ->map(fn ($v, $k) => is_array($v) ? implode(', ', $v) : (string) $v)
                ->filter(fn ($v) => $v !== '')
                ->map(fn ($v, $k) => ucfirst(str_replace('_', ' ', $k)) . ': ' . $v)
                ->values()
                ->take(6)
                ->all();
            foreach ($locked as $item) {
                $lines[] = "- {$item}";
            }
        }

        // Required features esplicite
        $required = (array) ($rules['required_features'] ?? []);
        foreach (array_filter(array_map('strval', $required)) as $feat) {
            $lines[] = '- ' . ucfirst(str_replace('_', ' ', $feat)) . ' (obbligatorio)';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, string>
     */
    private function featureLabelsForType(string $type): array
    {
        return match ($type) {
            IdentityAsset::TYPE_PERSON => [
                'hair'         => 'capelli',
                'hair_color'   => 'colore capelli',
                'skin'         => 'carnagione',
                'eyes'         => 'occhi',
                'eye_color'    => 'colore occhi',
                'build'        => 'corporatura',
                'age_range'    => 'fascia d\'età',
                'style'        => 'stile abbigliamento',
                'distinguishing_marks' => 'segni distintivi',
                'face_shape'   => 'forma del viso',
            ],
            IdentityAsset::TYPE_PRODUCT => [
                'color'        => 'colore principale',
                'shape'        => 'forma',
                'material'     => 'materiale',
                'logo_position' => 'posizione logo',
                'dimensions'   => 'dimensioni',
                'texture'      => 'texture',
                'finish'       => 'finitura',
            ],
            IdentityAsset::TYPE_LOCATION => [
                'architecture' => 'architettura',
                'color_palette' => 'palette colore',
                'vegetation'   => 'vegetazione',
                'distinctive_elements' => 'elementi distintivi',
                'lighting'     => 'illuminazione tipica',
                'season'       => 'stagione',
                'time_of_day'  => 'ora del giorno',
            ],
            IdentityAsset::TYPE_BRAND_OBJECT => [
                'color'        => 'colore',
                'shape'        => 'forma',
                'material'     => 'materiale',
                'size'         => 'dimensione',
                'logo'         => 'logo presente',
                'texture'      => 'texture',
            ],
            default => [],
        };
    }
}
