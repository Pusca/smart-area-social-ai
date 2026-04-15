<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Calcola quanto una caption generata aderisce alla voce dell'alter ego attivo.
 *
 * Il punteggio è non-bloccante: se fallisce, la generazione procede normalmente
 * e viene solo loggato l'errore. Il risultato viene salvato in ai_meta.alter_ego_consistency.
 */
class AlterEgoConsistencyService
{
    public function __construct(private readonly OpenAiService $openAi)
    {
    }

    /**
     * Valuta la coerenza di una caption con il persona dell'alter ego.
     *
     * @param  array<string, mixed>  $alterEgoContext  (da GenerationPipelineState)
     * @return array<string, mixed>  Score + feedback, oppure [] se il check non è possibile
     */
    public function score(string $caption, array $alterEgoContext): array
    {
        $personaPrompt = trim((string) ($alterEgoContext['persona_prompt'] ?? ''));
        $caption       = trim($caption);

        if ($personaPrompt === '' || $caption === '') {
            return [];
        }

        try {
            $result = $this->openAi->scoreAlterEgoConsistency($caption, $personaPrompt);

            if (empty($result)) {
                return [];
            }

            return array_merge($result, [
                'alter_ego_id'   => $alterEgoContext['id'] ?? null,
                'alter_ego_name' => (string) ($alterEgoContext['name'] ?? ''),
                'scored_at'      => now()->toDateTimeString(),
            ]);
        } catch (Throwable $e) {
            Log::info('AlterEgoConsistencyService score failed (non-blocking)', [
                'error'          => $e->getMessage(),
                'alter_ego_id'   => $alterEgoContext['id'] ?? null,
                'caption_length' => strlen($caption),
            ]);

            return [];
        }
    }

    /**
     * Classi CSS Tailwind per il badge del punteggio.
     */
    public static function scoreBadgeClass(int $score): string
    {
        if ($score >= 80) {
            return 'bg-emerald-100 text-emerald-700 border-emerald-200';
        }

        if ($score >= 60) {
            return 'bg-amber-100 text-amber-700 border-amber-200';
        }

        return 'bg-red-100 text-red-600 border-red-200';
    }

    /**
     * Etichetta testuale per il punteggio.
     */
    public static function scoreLabel(int $score): string
    {
        if ($score >= 80) {
            return 'Alta aderenza';
        }

        if ($score >= 60) {
            return 'Aderenza media';
        }

        return 'Bassa aderenza';
    }
}
