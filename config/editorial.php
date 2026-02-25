<?php

return [
    'duplicate_window_days' => (int) env('EDITORIAL_DUPLICATE_WINDOW_DAYS', 180),
    'soft_similarity_threshold' => (float) env('EDITORIAL_SOFT_SIMILARITY_THRESHOLD', 0.78),
    'max_regeneration_attempts' => (int) env('EDITORIAL_MAX_REGEN_ATTEMPTS', 2),
    'history_limit' => (int) env('EDITORIAL_HISTORY_LIMIT', 120),

    'rubrics' => [
        ['name' => 'Educativo', 'weight' => 0.40],
        ['name' => 'Prova Sociale', 'weight' => 0.20],
        ['name' => 'Storia Brand', 'weight' => 0.20],
        ['name' => 'Offerta', 'weight' => 0.20],
    ],

    'trend' => [
        'enabled' => env('EDITORIAL_TREND_ENABLED', false),
        'ttl_minutes' => (int) env('EDITORIAL_TREND_TTL_MINUTES', 180),
        'max_posts_per_plan' => (int) env('EDITORIAL_TREND_MAX_POSTS', 2),
        'sources' => [
            'https://blog.hootsuite.com/feed/',
            'https://later.com/blog/rss/',
            'https://buffer.com/resources/rss/',
        ],
    ],
];
