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

    'hook' => [
        'ideal_min_chars' => 18,
        'ideal_max_chars' => 110,
        'hard_max_chars' => 140,
    ],
];
