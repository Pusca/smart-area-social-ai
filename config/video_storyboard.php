<?php

return [
    'version' => 'video_scene_planner_v1',

    'duration_defaults_ms' => [
        'reel' => 20000,
        'video' => 16000,
        'story' => 12000,
    ],

    'timing' => [
        'hook_min_ms' => 2200,
        'hook_max_ms' => 3000,
        'cta_min_ms' => 1800,
        'cta_max_ms' => 2800,
        'min_scene_ms' => 1200,
    ],

    'scene_patterns' => [
        'hook' => [
            'position' => 'upper_left',
            'safe_area' => 'upper_third',
            'font_size_mode' => 'xl',
            'background_style' => 'dark_box',
            'animation_style' => 'pop',
            'max_lines' => 2,
        ],
        'development' => [
            'position' => 'center_left',
            'safe_area' => 'center_safe',
            'font_size_mode' => 'large',
            'background_style' => 'gradient_strip',
            'animation_style' => 'fade',
            'max_lines' => 2,
        ],
        'payoff' => [
            'position' => 'lower_left',
            'safe_area' => 'lower_third',
            'font_size_mode' => 'large',
            'background_style' => 'dark_box',
            'animation_style' => 'fade',
            'max_lines' => 2,
        ],
        'cta' => [
            'position' => 'lower_center',
            'safe_area' => 'lower_third',
            'font_size_mode' => 'medium',
            'background_style' => 'dark_box',
            'animation_style' => 'fade',
            'max_lines' => 2,
        ],
    ],

    'safe_area_profiles' => [
        'default' => [
            'hook' => [
                'position' => 'upper_center',
                'safe_area' => 'upper_third',
                'avoid_regions' => ['center_focus_zone'],
            ],
            'development' => [
                'position' => 'center_left',
                'safe_area' => 'center_safe',
                'avoid_regions' => [],
            ],
            'payoff' => [
                'position' => 'lower_left',
                'safe_area' => 'lower_third',
                'avoid_regions' => [],
            ],
            'cta' => [
                'position' => 'lower_center',
                'safe_area' => 'lower_third',
                'avoid_regions' => [],
            ],
        ],
        'identity_first' => [
            'hook' => [
                'position' => 'upper_left',
                'safe_area' => 'upper_third',
                'avoid_regions' => ['center_face_zone', 'hero_product_zone', 'brand_logo_zone'],
            ],
            'development' => [
                'position' => 'upper_left',
                'safe_area' => 'upper_third',
                'avoid_regions' => ['center_face_zone', 'hero_product_zone'],
            ],
            'payoff' => [
                'position' => 'lower_left',
                'safe_area' => 'lower_third',
                'avoid_regions' => ['center_face_zone', 'hero_product_zone'],
            ],
            'cta' => [
                'position' => 'lower_center',
                'safe_area' => 'lower_third',
                'avoid_regions' => ['center_face_zone', 'hero_product_zone', 'brand_logo_zone'],
            ],
        ],
    ],

    'continuity_requirements' => [
        'identity_first' => [
            'Mantieni volto, prodotto e luogo coerenti tra le scene.',
            'Non usare overlay al centro se possono coprire persona, prodotto o dettaglio identitario chiave.',
            'Lascia sempre una fascia leggibile per overlay senza rovinare il soggetto principale.',
        ],
        'default' => [
            'Mantieni ritmo, palette e gerarchia visiva coerenti tra le scene.',
            'Usa overlay brevi e mobili-first, senza saturare il frame.',
        ],
    ],
];
