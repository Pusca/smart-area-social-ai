<?php

return [
    'base_url' => env('RUNWAY_BASE_URL', 'https://api.dev.runwayml.com'),
    'api_key' => env('RUNWAY_API_KEY'),
    'api_version' => env('RUNWAY_API_VERSION', '2024-11-06'),

    'model' => env('RUNWAY_VIDEO_MODEL', 'gen4_turbo'),
    'video_seconds' => (int) env('RUNWAY_VIDEO_SECONDS', 8),
    'video_ratio' => env('RUNWAY_VIDEO_RATIO', ''),
    'max_prompt_chars' => (int) env('RUNWAY_MAX_PROMPT_CHARS', 980),

    'create_endpoint' => env('RUNWAY_CREATE_ENDPOINT', '/v1/image_to_video'),
    'retrieve_endpoint' => env('RUNWAY_RETRIEVE_ENDPOINT', '/v1/tasks/{id}'),

    'timeout_create' => (int) env('RUNWAY_TIMEOUT_CREATE', 60),
    'timeout_poll' => (int) env('RUNWAY_TIMEOUT_POLL', 60),
    'timeout_download' => (int) env('RUNWAY_TIMEOUT_DOWNLOAD', 240),
    'connect_timeout' => (int) env('RUNWAY_CONNECT_TIMEOUT', 15),

    'poll_interval' => (int) env('RUNWAY_VIDEO_POLL_INTERVAL', 8),
    'poll_timeout' => (int) env('RUNWAY_VIDEO_POLL_TIMEOUT', 420),
];
