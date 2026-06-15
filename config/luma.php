<?php

return [
    'api_key'           => env('LUMA_API_KEY', ''),
    'base_url'          => env('LUMA_BASE_URL', 'https://agents.lumalabs.ai'),
    'timeout'           => (int) env('LUMA_TIMEOUT', 60),
    'poll_interval'     => (int) env('LUMA_POLL_INTERVAL', 5),
    'poll_max_attempts' => (int) env('LUMA_POLL_MAX_ATTEMPTS', 60),

    'image' => [
        'model_default' => env('LUMA_IMAGE_MODEL', 'uni-1'),
        'aspect_ratio'  => env('LUMA_IMAGE_ASPECT_RATIO', '1:1'),
    ],
];
