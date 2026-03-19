<?php

return [
    // IMPORTANTISSIMO: SENZA /v1
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
    'api_key' => env('OPENAI_API_KEY'),
    'proxy' => env('OPENAI_PROXY'),
    'force_ipv4' => env('OPENAI_FORCE_IPV4', false),

    'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-4.1-mini'),
    'fine_tuning_base_model' => env('OPENAI_FINE_TUNING_BASE_MODEL', env('OPENAI_TEXT_MODEL', 'gpt-4.1-mini')),
    'vision_model' => env('OPENAI_VISION_MODEL', env('OPENAI_TEXT_MODEL', 'gpt-4.1-mini')),
    'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
    'video_model' => env('OPENAI_VIDEO_MODEL', 'sora-2'),
    'speech_model' => env('OPENAI_SPEECH_MODEL', 'gpt-4o-mini-tts'),
    'speech_voice' => env('OPENAI_SPEECH_VOICE', 'alloy'),
    'speech_max_chars' => (int) env('OPENAI_SPEECH_MAX_CHARS', 1000),

    'timeout' => env('OPENAI_TIMEOUT', 60),
    'fine_tuning_timeout' => env('OPENAI_FINE_TUNING_TIMEOUT', env('OPENAI_TIMEOUT', 60)),
    'timeout_images' => env('OPENAI_TIMEOUT_IMAGES', 120),
    'timeout_speech' => env('OPENAI_TIMEOUT_SPEECH', 90),
    'timeout_video_create' => env('OPENAI_TIMEOUT_VIDEO_CREATE', 60),
    'timeout_video_poll' => env('OPENAI_TIMEOUT_VIDEO_POLL', 60),
    'timeout_video_download' => env('OPENAI_TIMEOUT_VIDEO_DOWNLOAD', 180),
    'connect_timeout' => env('OPENAI_CONNECT_TIMEOUT', 15),

    'image_size' => env('OPENAI_IMAGE_SIZE', '1024x1024'),
    'video_size' => env('OPENAI_VIDEO_SIZE', '720x1280'),
    'video_seconds' => env('OPENAI_VIDEO_SECONDS', '8'),
    'video_poll_interval' => env('OPENAI_VIDEO_POLL_INTERVAL', 10),
    'video_poll_timeout' => env('OPENAI_VIDEO_POLL_TIMEOUT', 420),
];
