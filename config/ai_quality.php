<?php

return [
    'version' => 'quality_scorecard_v2',
    'status_thresholds' => [
        'manual_review_score' => 0.55,
        'warning_score' => 0.72,
        'blocked_reference_score' => 0.45,
        'blocked_caption_score' => 0.35,
    ],

    'score_thresholds' => [
        'professionalism' => [
            'warning' => 0.64,
            'blocked' => 0.36,
        ],
        'hook_strength' => [
            'warning' => 0.58,
            'blocked' => 0.34,
        ],
        'first_seconds_strength' => [
            'warning' => 0.62,
            'blocked' => 0.38,
        ],
        'trend_relevance' => [
            'warning' => 0.6,
            'blocked' => 0.42,
        ],
        'trend_brand_fit' => [
            'warning' => 0.66,
            'blocked' => 0.46,
        ],
        'overlay_readability' => [
            'warning' => 0.62,
            'blocked' => 0.38,
        ],
        'mobile_legibility' => [
            'warning' => 0.68,
            'blocked' => 0.42,
        ],
        'viral_readiness' => [
            'warning' => 0.6,
        ],
    ],

    'viral_readiness_weights' => [
        'hook_strength_score' => 0.24,
        'first_seconds_strength_score' => 0.18,
        'trend_relevance_score' => 0.12,
        'trend_brand_fit_score' => 0.12,
        'overlay_readability_score' => 0.1,
        'mobile_legibility_score' => 0.08,
        'professionalism_score' => 0.1,
        'asset_identity_confidence_score' => 0.06,
    ],

    'professionalism' => [
        // Frammenti aggressivi generici — validi per tutti i settori.
        // Le industry_overrides qui sotto aggiungono regole settoriali specifiche.
        'aggressive_fragments' => [
            'compra subito',
            'ultima occasione',
            'non perdere',
            'solo oggi',
            'affrettati',
            'dm subito',
            'clicca ora',
            'offerta shock',
        ],
        'max_exclamation_marks' => 2,
    ],

    /**
     * Regole di professionalismo specifiche per industria.
     *
     * Per ogni industry (chiave = valore del campo TenantProfile::industry),
     * è possibile definire:
     *   - aggressive_fragments: parole/frasi vietate IN AGGIUNTA alle generiche
     *   - max_exclamation_marks: override del limite esclamativo
     *
     * Le industry sono case-insensitive e vengono confrontate con strtolower().
     * Se l'industry del tenant non è presente, si usano le regole generiche.
     */
    'industry_overrides' => [
        // Salute, cliniche, medici: nessuna promessa di cura, nessuno sconto urgente
        'healthcare' => [
            'aggressive_fragments' => [
                'guarisci subito',
                'cura definitiva',
                'risultati garantiti',
                'offerta speciale',
                'prenota ora',
                'sconto esclusivo',
                'solo questo mese',
            ],
            'max_exclamation_marks' => 1,
        ],

        // Avvocati, notai, consulenti legali: nessuna promessa di vittoria, no urgenza
        'legal' => [
            'aggressive_fragments' => [
                'vinciamo garantito',
                'risultato assicurato',
                'consulenza gratuita subito',
                'chiama ora',
                'non perdere tempo',
            ],
            'max_exclamation_marks' => 1,
        ],

        // Finanza e assicurazioni: no promesse di rendimento
        'finance' => [
            'aggressive_fragments' => [
                'rendimento garantito',
                'guadagno sicuro',
                'investimento infallibile',
                'arricchisciti',
                'soldi facili',
                'offerta irripetibile',
            ],
            'max_exclamation_marks' => 1,
        ],

        // Food & restaurant: tono più caldo, regole più permissive
        'food' => [
            'aggressive_fragments' => [
                'offerta shock',
                'prezzi stracciati',
            ],
            'max_exclamation_marks' => 3,
        ],

        // Formazione e infoprodotti: no promesse di trasformazione rapida
        'education' => [
            'aggressive_fragments' => [
                'impara in 24 ore',
                'diventa esperto subito',
                'risultati immediati',
                'successo garantito',
            ],
            'max_exclamation_marks' => 2,
        ],

        // E-commerce: tono commerciale ammesso, ma non aggressivo
        'ecommerce' => [
            'aggressive_fragments' => [
                'offerta shock',
                'prezzi da shock',
                'ultima unità',
            ],
            'max_exclamation_marks' => 2,
        ],
    ],

    'hook' => [
        'ideal_min_chars' => 18,
        'ideal_max_chars' => 110,
        'hard_max_chars' => 140,
    ],
];
