<?php

namespace App\Support;

use Illuminate\Support\Str;

class ImagePromptRealismGuard
{
    public static function shouldForcePhotorealism(?string $brief = null, ?string $prompt = null): bool
    {
        $text = self::normalize(trim((string) $brief) . ' ' . trim((string) $prompt));
        if ($text === '') {
            return true;
        }

        foreach (self::creativeStyleHints() as $hint) {
            if (str_contains($text, $hint)) {
                return false;
            }
        }

        return true;
    }

    public static function sanitize(string $prompt, bool $forcePhotorealism): string
    {
        $clean = trim($prompt);
        if ($clean === '' || !$forcePhotorealism) {
            return $clean;
        }

        $replacements = [
            'clienti stilizzati' => 'clienti reali e naturali',
            'cliente stilizzato' => 'cliente reale e naturale',
            'persone stilizzate' => 'persone reali e naturali',
            'persona stilizzata' => 'persona reale e naturale',
            'volti stilizzati' => 'volti reali e naturali',
            'volto stilizzato' => 'volto reale e naturale',
            'ospiti stilizzati' => 'ospiti reali e naturali',
            'guest stylized' => 'real guests with natural appearance',
            'stylized guests' => 'real guests with natural appearance',
            'stile illustrativo' => 'stile fotografico realistico',
            'stile illustrato' => 'stile fotografico realistico',
            'illustrative style' => 'photographic realistic style',
        ];

        $clean = str_ireplace(array_keys($replacements), array_values($replacements), $clean);
        $clean = preg_replace('/\b(?:cartoon|anime|manga|fumetto|comic|cgi|3d|render(?:ing)?|surreal|surreale)\b/iu', 'fotografico', $clean) ?? $clean;
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

        return trim($clean);
    }

    public static function instruction(
        bool $forcePhotorealism,
        bool $locationEnvelopeProtected = false,
        bool $hasExplicitHumanReferences = false
    ): string {
        if (!$forcePhotorealism) {
            return '';
        }

        $parts = [
            'Default visivo: fotografia editoriale e commerciale fotorealistica.',
            'Persone, volti, occhi, denti, mani, postura e anatomia devono sembrare veri, naturali e credibili.',
            'Evita qualunque look AI, uncanny, plastico, illustrato, cartoon, CGI o render 3D.',
        ];

        if ($locationEnvelopeProtected) {
            $parts[] = 'Se la scena parte da un luogo reale, mantieni il posto autentico e riconoscibile.';
        }

        if (!$hasExplicitHumanReferences) {
            $parts[] = 'Se aggiungi persone non presenti nei riferimenti, preferisci clienti o staff credibili in media distanza, pose spontanee e interazioni naturali, evitando primi piani di volti inventati.';
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<int, string>
     */
    private static function creativeStyleHints(): array
    {
        return [
            'illustrazione',
            'illustrato',
            'illustrata',
            'illustrativo',
            'cartoon',
            'anime',
            'manga',
            'fumetto',
            'comic',
            'vettoriale',
            'vector',
            'flat design',
            '3d',
            'cgi',
            'render',
            'surreale',
            'surreal',
            'acquerello',
            'watercolor',
            'schizzo',
            'sketch',
            'pixar',
        ];
    }

    private static function normalize(string $value): string
    {
        $value = Str::of($value)->lower()->ascii()->toString();
        $value = preg_replace('/[^a-z0-9\s_-]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return trim($value);
    }
}
