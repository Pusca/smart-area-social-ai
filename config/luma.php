<?php

return [
    'api_key'     => env('LUMA_API_KEY', ''),
    'base_url'    => env('LUMA_BASE_URL', 'https://api.lumalabs.ai'),
    'timeout'     => (int) env('LUMA_TIMEOUT', 60),
    'poll_interval' => (int) env('LUMA_POLL_INTERVAL', 5),
    'poll_max_attempts' => (int) env('LUMA_POLL_MAX_ATTEMPTS', 60),

    'image' => [
        'model_default' => env('LUMA_IMAGE_MODEL', 'photon-1'),
        'model_fast'    => env('LUMA_IMAGE_MODEL_FAST', 'photon-flash-1'),
        'aspect_ratio'  => env('LUMA_IMAGE_ASPECT_RATIO', '1:1'),
    ],

    'video' => [
        'model_standard' => env('LUMA_VIDEO_MODEL', 'ray-2'),
        'model_fast'     => env('LUMA_VIDEO_MODEL_FAST', 'ray-flash-2'),
        'duration_options' => ['5s', '10s', '15s'],
        'duration_default' => env('LUMA_VIDEO_DURATION_DEFAULT', '5s'),
    ],
];
