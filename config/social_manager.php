<?php

return [
    'features' => [
        'trend_brief_v1' => env('SOCIAL_MANAGER_TREND_BRIEF_V1', true),
        'creative_brief_v1' => env('SOCIAL_MANAGER_CREATIVE_BRIEF_V1', true),
        'identity_guard_v1' => env('SOCIAL_MANAGER_IDENTITY_GUARD_V1', true),
        'publish_gate_v1' => env('SOCIAL_MANAGER_PUBLISH_GATE_V1', true),
        'tenant_learning_v1' => env('SOCIAL_MANAGER_TENANT_LEARNING_V1', true),
        'canva_integration_v1' => env('SOCIAL_MANAGER_CANVA_INTEGRATION_V1', true),
    ],

    'trend_brief' => [
        'version' => 'trend_brief_v1',
        'max_themes' => 6,
        'max_angles' => 6,
        'max_reel_structures' => 4,
        'max_hook_patterns' => 8,
        'max_cta_styles' => 5,
        'fresh_signal_hours' => 24,
        'expiring_signal_hours' => 72,
        'default_expiry_hours' => 96,
    ],

    'creative_brief' => [
        'version' => 'creative_brief_v1',
        'max_forbidden_elements' => 10,
        'max_proof_points' => 6,
        'max_identity_constraints' => 6,
    ],

    'identity_guard' => [
        'version' => 'identity_guard_v1',
        'thresholds' => [
            'person_identity_min_score' => 0.78,
            'product_integrity_min_score' => 0.76,
            'location_consistency_min_score' => 0.74,
            'generic_identity_min_score' => 0.72,
        ],
    ],

    'publish_gate' => [
        'version' => 'publish_gate_v1',
        'allow_statuses' => ['pass', 'pass_with_warnings'],
    ],

    'learning' => [
        'version' => 'tenant_learning_v1',
        'window_days' => 90,
        'min_events_for_bias' => 2,
        'max_preferred_items' => 6,
        'max_underperforming_items' => 5,
        'max_provider_paths' => 5,
    ],
];
