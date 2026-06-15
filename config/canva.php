<?php

return [
    'enabled' => env('CANVA_INTEGRATION_ENABLED', true),

    'client_id' => env('CANVA_CLIENT_ID'),
    'client_secret' => env('CANVA_CLIENT_SECRET'),
    'redirect_uri' => env('CANVA_REDIRECT_URI'),

    'authorize_url' => env('CANVA_AUTHORIZE_URL', 'https://www.canva.com/api/oauth/authorize'),
    'token_url' => env('CANVA_TOKEN_URL', 'https://api.canva.com/rest/v1/oauth/token'),
    'api_base_url' => env('CANVA_API_BASE_URL', 'https://api.canva.com/rest/v1'),
    'manual_editor_url' => env('CANVA_MANUAL_EDITOR_URL', 'https://www.canva.com/'),

    'scopes' => [
        'profile:read',
        'asset:read',
        'asset:write',
        'brandtemplate:meta:read',
        'brandtemplate:content:read',
        'design:meta:read',
        'design:content:read',
        'design:content:write',
    ],

    'http_timeout_seconds' => (int) env('CANVA_HTTP_TIMEOUT_SECONDS', 30),
    'token_refresh_leeway_seconds' => (int) env('CANVA_TOKEN_REFRESH_LEEWAY_SECONDS', 300),
    'asset_upload_poll_attempts' => (int) env('CANVA_ASSET_UPLOAD_POLL_ATTEMPTS', 5),
    'asset_upload_poll_sleep_ms' => (int) env('CANVA_ASSET_UPLOAD_POLL_SLEEP_MS', 800),
    'job_poll_delay_seconds' => (int) env('CANVA_JOB_POLL_DELAY_SECONDS', 12),
    'job_max_polls' => (int) env('CANVA_JOB_MAX_POLLS', 12),
    'catalog_preview_limit' => (int) env('CANVA_CATALOG_PREVIEW_LIMIT', 12),

    'queues' => [
        'autofill_poll' => env('CANVA_AUTOFILL_QUEUE', 'canva-autofill'),
        'export_poll' => env('CANVA_EXPORT_QUEUE', 'canva-export'),
    ],

    'workflows' => [
        'instagram_post' => [
            'label' => 'Instagram post',
            'default_export_type' => 'png',
        ],
        'carousel' => [
            'label' => 'Carousel',
            'default_export_type' => 'png',
        ],
        'story' => [
            'label' => 'Story',
            'default_export_type' => 'png',
        ],
        'investor_presentation' => [
            'label' => 'Investor presentation',
            'default_export_type' => 'pptx',
        ],
    ],
];
