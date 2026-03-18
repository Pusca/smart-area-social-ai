<?php

namespace App\Services\AI;

use App\Services\OpenAiService;
use Illuminate\Support\Str;

class ContentAlignmentService
{
    public function __construct(
        private readonly OpenAiService $openAi
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $generated
     * @param  array<string, mixed>  $providerMatrix
     * @return array<string, mixed>
     */
    public function gradeTextDraft(array $context, array $generated, array $providerMatrix = []): array
    {
        $heuristic = $this->heuristicTextSignals($context, $generated);
        $provider = strtolower(trim((string) data_get($providerMatrix, 'grader.provider', 'openai')));
        $llm = null;

        if ($provider === 'openai') {
            $llm = $this->openAi->gradeGeneratedDraft([
                'context' => $context,
                'generated' => $generated,
                'heuristic' => $heuristic,
            ]);
        }

        $overall = $heuristic['overall_score'];
        if (is_array($llm)) {
            $overall = round(((float) ($heuristic['overall_score'] ?? 0.0) * 0.35) + ((float) ($llm['overall_score'] ?? 0.0) * 0.65), 4);
        }

        return [
            'provider' => $provider,
            'heuristic' => $heuristic,
            'llm' => $llm,
            'overall_score' => $overall,
            'should_retry' => $overall < (float) (config('generation.alignment_text_min_score') ?: 0.64),
            'feedback' => $this->buildRetryFeedback($heuristic, $llm),
            'graded_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * @param  array<int, string>  $referenceAbsolutePaths
     * @param  array<string, mixed>  $providerMatrix
     * @return array<string, mixed>|null
     */
    public function validateGeneratedImageCandidate(
        string $brief,
        string $generatedAbsolutePath,
        array $referenceAbsolutePaths,
        array $providerMatrix = []
    ): ?array {
        if (!is_file($generatedAbsolutePath) || empty($referenceAbsolutePaths)) {
            return null;
        }

        $provider = strtolower(trim((string) data_get($providerMatrix, 'grader.provider', 'openai')));
        if ($provider !== 'openai') {
            return null;
        }

        $result = $this->openAi->validateGeneratedImageWithReferences($brief, $generatedAbsolutePath, $referenceAbsolutePaths);
        if (!is_array($result)) {
            return null;
        }

        return $result + [
            'provider' => $provider,
            'validated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $generated
     * @return array<string, mixed>
     */
    private function heuristicTextSignals(array $context, array $generated): array
    {
        $brief = Str::lower(trim((string) data_get($context, 'item_brain.angle', data_get($context, 'manual_brief', ''))));
        $caption = Str::lower(trim((string) ($generated['caption'] ?? '')));
        $cta = Str::lower(trim((string) ($generated['cta'] ?? '')));
        $imagePrompt = Str::lower(trim((string) ($generated['image_prompt'] ?? '')));

        $keywords = $this->extractKeywords($brief);
        $matches = 0;
        foreach ($keywords as $keyword) {
            if (Str::contains($caption . ' ' . $imagePrompt, $keyword)) {
                $matches++;
            }
        }

        $coverage = empty($keywords) ? 0.7 : min(1.0, $matches / max(1, count($keywords)));
        $preferredCta = Str::lower(trim((string) data_get($context, 'brand.cta', data_get($context, 'brand.default_cta', ''))));
        $ctaScore = ($preferredCta !== '' && $cta !== '' && Str::contains($cta, Str::before($preferredCta, ' ')))
            ? 0.9
            : ($cta !== '' ? 0.7 : 0.35);

        $hardAvoidRules = collect((array) data_get($context, 'knowledge_pack.feedback.hard_avoid_rules', []))
            ->map(fn ($value) => Str::lower((string) $value))
            ->filter()
            ->values()
            ->all();
        $violations = [];
        foreach ($hardAvoidRules as $rule) {
            $needle = trim((string) Str::before($rule, ':'));
            if ($needle !== '' && Str::contains($caption . ' ' . $imagePrompt . ' ' . $cta, $needle)) {
                $violations[] = $rule;
            }
        }

        $overall = round(max(0.0, min(1.0, ($coverage * 0.55) + ($ctaScore * 0.25) + (empty($violations) ? 0.2 : 0.0))), 4);

        return [
            'brief_keyword_coverage' => round($coverage, 4),
            'cta_score' => round($ctaScore, 4),
            'hard_rule_violations' => array_values(array_unique($violations)),
            'overall_score' => $overall,
        ];
    }

    /**
     * @param  array<string, mixed>  $heuristic
     * @param  array<string, mixed>|null  $llm
     */
    private function buildRetryFeedback(array $heuristic, ?array $llm): ?array
    {
        $issues = [];
        if (((float) ($heuristic['brief_keyword_coverage'] ?? 0.0)) < 0.5) {
            $issues[] = 'La bozza non copre abbastanza bene il brief o il focus del cliente.';
        }
        if (!empty($heuristic['hard_rule_violations'])) {
            $issues[] = 'Evita i segnali contrari alle preferenze o ai divieti storici del tenant.';
        }

        if (is_array($llm)) {
            foreach ((array) ($llm['issues'] ?? []) as $issue) {
                $issue = trim((string) $issue);
                if ($issue !== '') {
                    $issues[] = $issue;
                }
            }
        }

        $issues = array_values(array_unique($issues));
        if (empty($issues)) {
            return null;
        }

        return [
            'reason' => 'La bozza non e ancora abbastanza allineata al cliente.',
            'issues' => $issues,
            'instruction' => 'Resta fedele al brand reale, agli esempi approvati e al brief, correggendo i problemi indicati senza diventare generico.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractKeywords(string $text): array
    {
        $text = Str::lower($this->normalizeUtf8($text));
        $text = preg_replace('/[^\\p{L}\\p{N}\\s]/u', ' ', $text) ?? '';
        $tokens = preg_split('/\s+/', trim($text)) ?: [];

        return collect($tokens)
            ->map(fn ($token) => trim((string) $token))
            ->filter(fn ($token) => $token !== '' && mb_strlen($token) >= 4)
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    private function normalizeUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            if (is_string($converted) && $converted !== '') {
                $text = $converted;
            }
        }

        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (is_string($sanitized)) {
            $text = $sanitized;
        }

        return $text;
    }
}

