<?php

namespace App\Services;

use App\Models\AlterEgo;

/**
 * Compila e mantiene aggiornato il persona_prompt_cache di un AlterEgo.
 *
 * Il prompt compilato è un blocco testuale che descrive la voce, lo stile,
 * i temi e il comportamento dell'alter ego — pronto per essere iniettato
 * come istruzione prioritaria nel pipeline di generazione AI.
 */
class AlterEgoService
{
    /**
     * Restituisce il persona prompt compilato, usando la cache se valida.
     */
    public function compiledPersonaPrompt(AlterEgo $alterEgo): string
    {
        if (empty($alterEgo->persona_prompt_cache)) {
            $this->recompile($alterEgo);
        }

        return (string) ($alterEgo->persona_prompt_cache ?? '');
    }

    /**
     * Rigenera e salva il persona_prompt_cache, incrementa la versione.
     */
    public function recompile(AlterEgo $alterEgo): void
    {
        $prompt = $this->buildPersonaPrompt($alterEgo);
        $alterEgo->persona_prompt_cache    = $prompt;
        $alterEgo->persona_prompt_version  = ($alterEgo->persona_prompt_version ?? 0) + 1;
        $alterEgo->save();
    }

    // ── Etichette leggibili ───────────────────────────────────────────────────

    public static function archetypeLabel(string $key): string
    {
        return match ($key) {
            'thought_leader' => 'Thought Leader',
            'educator'       => 'Educatore',
            'storyteller'    => 'Storyteller',
            'entertainer'    => 'Entertainer',
            'provocateur'    => 'Provocatore',
            'connector'      => 'Connector',
            default          => ucfirst($key),
        };
    }

    public static function toneLabel(string $key): string
    {
        return match ($key) {
            'autorevole'  => 'Autorevole',
            'diretto'     => 'Diretto',
            'empatico'    => 'Empatico',
            'ironico'     => 'Ironico',
            'formale'     => 'Formale',
            'colloquiale' => 'Colloquiale',
            default       => ucfirst($key),
        };
    }

    public static function sentenceStyleLabel(string $key): string
    {
        return match ($key) {
            'brevi_incisive'    => 'Frasi brevi e incisive',
            'narrative'         => 'Narrativo e descrittivo',
            'domande_retoriche' => 'Domande retoriche',
            default             => ucfirst($key),
        };
    }

    public static function vocabularyLabel(string $key): string
    {
        return match ($key) {
            'tecnico'     => 'Tecnico/specialistico',
            'accessibile' => 'Accessibile/divulgativo',
            'misto'       => 'Misto (tecnico + accessibile)',
            default       => ucfirst($key),
        };
    }

    public static function audienceRoleLabel(string $key): string
    {
        return match ($key) {
            'teacher'     => 'Teacher (insegna al pubblico)',
            'peer'        => 'Peer (alla pari con il pubblico)',
            'mentor'      => 'Mentor (guida con esperienza)',
            'challenger'  => 'Challenger (sfida il pensiero comune)',
            'entertainer' => 'Entertainer (intrattiene e diverte)',
            default       => ucfirst($key),
        };
    }

    public static function ctaStyleLabel(string $key): string
    {
        return match ($key) {
            'soft'    => 'Soft (invito gentile)',
            'direct'  => 'Diretto (call to action esplicita)',
            'domanda' => 'Domanda (coinvolge con una domanda)',
            'nessuna' => 'Nessuna CTA',
            default   => ucfirst($key),
        };
    }

    // ── Compilazione prompt ───────────────────────────────────────────────────

    private function buildPersonaPrompt(AlterEgo $alterEgo): string
    {
        $lines = [];

        $lines[] = "PERSONA OPERATIVA — {$alterEgo->name}";
        $lines[] = 'Archetipo: ' . self::archetypeLabel($alterEgo->archetype ?? '');
        $lines[] = '';

        // Voce
        $lines[] = 'VOCE E STILE';
        if (!empty($alterEgo->tone)) {
            $lines[] = 'Tono: ' . self::toneLabel($alterEgo->tone);
        }
        if (!empty($alterEgo->sentence_style)) {
            $lines[] = 'Struttura frasi: ' . self::sentenceStyleLabel($alterEgo->sentence_style);
        }
        if (!empty($alterEgo->vocabulary_level)) {
            $lines[] = 'Vocabolario: ' . self::vocabularyLabel($alterEgo->vocabulary_level);
        }
        $phrases = array_filter((array) ($alterEgo->signature_phrases ?? []));
        if (!empty($phrases)) {
            $lines[] = 'Frasi caratteristiche: ' . implode(' | ', $phrases);
        }
        $lines[] = '';

        // Temi
        $owned   = array_filter((array) ($alterEgo->topics_owned ?? []));
        $avoided = array_filter((array) ($alterEgo->topics_avoided ?? []));
        if (!empty($owned) || !empty($avoided) || !empty($alterEgo->unique_perspective)) {
            $lines[] = 'TEMI';
            if (!empty($owned)) {
                $lines[] = 'Temi di proprietà: ' . implode(', ', $owned);
            }
            if (!empty($avoided)) {
                $lines[] = 'Temi da evitare: ' . implode(', ', $avoided);
            }
            if (!empty($alterEgo->unique_perspective)) {
                $lines[] = 'Prospettiva unica: ' . trim((string) $alterEgo->unique_perspective);
            }
            $lines[] = '';
        }

        // Pubblico
        if (!empty($alterEgo->audience_role) || !empty($alterEgo->cta_style)) {
            $lines[] = 'RELAZIONE CON IL PUBBLICO';
            if (!empty($alterEgo->audience_role)) {
                $lines[] = 'Ruolo: ' . self::audienceRoleLabel($alterEgo->audience_role);
            }
            if (!empty($alterEgo->cta_style)) {
                $lines[] = 'Stile CTA: ' . self::ctaStyleLabel($alterEgo->cta_style);
            }
            $lines[] = '';
        }

        // Campioni
        $samples = array_values(array_filter(array_map(
            fn ($s) => trim((string) $s),
            (array) ($alterEgo->training_samples ?? [])
        )));
        if (!empty($samples)) {
            $lines[] = 'ESEMPI DI VOCE (campioni reali approvati)';
            foreach ($samples as $i => $sample) {
                $lines[] = '---';
                $lines[] = $sample;
            }
            $lines[] = '---';
            $lines[] = '';
        }

        // Istruzione operativa
        $lines[] = 'ISTRUZIONE OPERATIVA';
        $lines[] = "Ogni caption, titolo, CTA e visual prompt deve riflettere questa persona come se fosse {$alterEgo->name} a scrivere direttamente per il proprio canale social.";
        $lines[] = 'Non usare il linguaggio generico di agenzia o copywriter esterno.';
        $lines[] = 'La voce deve essere immediatamente riconoscibile, coerente e autentica a questo alter ego.';
        $lines[] = 'Archetipo, tono, frasi caratteristiche e temi di proprietà hanno priorità su qualsiasi stile di default.';

        return implode("\n", $lines);
    }
}
