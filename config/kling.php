<?php

return [
    'base_url' => env('KLING_BASE_URL', 'https://api-singapore.klingai.com'),
    'access_key' => env('KLING_ACCESS_KEY'),
    'secret_key' => env('KLING_SECRET_KEY'),

    'model' => env('KLING_VIDEO_MODEL', ''),
    'mode' => env('KLING_VIDEO_MODE', 'pro'),
    'video_seconds' => (int) env('KLING_VIDEO_SECONDS', 5),
    'video_ratio' => env('KLING_VIDEO_RATIO', '9:16'),
    'cfg_scale' => (float) env('KLING_CFG_SCALE', 0.78),
    'negative_prompt' => env('KLING_NEGATIVE_PROMPT', ''),
    'sound' => env('KLING_SOUND', ''),
    'callback_url' => env('KLING_CALLBACK_URL', ''),
    'max_prompt_chars' => (int) env('KLING_MAX_PROMPT_CHARS', 1400),
    'max_reference_images' => (int) env('KLING_MAX_REFERENCE_IMAGES', 4),

    'text_create_endpoint' => env('KLING_TEXT_CREATE_ENDPOINT', '/v1/videos/text2video'),
    'image_create_endpoint' => env('KLING_IMAGE_CREATE_ENDPOINT', '/v1/videos/image2video'),
    'multi_image_create_endpoint' => env('KLING_MULTI_IMAGE_CREATE_ENDPOINT', '/v1/videos/multi-image2video'),
    'retrieve_endpoint' => env('KLING_RETRIEVE_ENDPOINT', '/v1/videos/{id}'),
    'text_retrieve_endpoint' => env('KLING_TEXT_RETRIEVE_ENDPOINT', '/v1/videos/text2video/{id}'),
    'image_retrieve_endpoint' => env('KLING_IMAGE_RETRIEVE_ENDPOINT', '/v1/videos/image2video/{id}'),
    'multi_image_retrieve_endpoint' => env('KLING_MULTI_IMAGE_RETRIEVE_ENDPOINT', '/v1/videos/multi-image2video/{id}'),

    'timeout_create' => (int) env('KLING_TIMEOUT_CREATE', 60),
    'timeout_poll' => (int) env('KLING_TIMEOUT_POLL', 60),
    'timeout_download' => (int) env('KLING_TIMEOUT_DOWNLOAD', 240),
    'connect_timeout' => (int) env('KLING_CONNECT_TIMEOUT', 15),

    'poll_interval' => (int) env('KLING_VIDEO_POLL_INTERVAL', 8),
    'poll_timeout' => (int) env('KLING_VIDEO_POLL_TIMEOUT', 540),
];


