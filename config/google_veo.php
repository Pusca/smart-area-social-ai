<?php

$envOrDefault = static function (string $key, string $default = ''): string {
    $value = trim((string) env($key, $default));

    return $value !== '' ? $value : $default;
};

return [
    'base_url' => $envOrDefault('GOOGLE_VEO_BASE_URL', 'https://generativelanguage.googleapis.com'),
    'api_key' => env('GOOGLE_VEO_API_KEY', env('NANOBANANA_API_KEY')),
    'api_version' => $envOrDefault('GOOGLE_VEO_API_VERSION', 'v1beta'),

    'model' => $envOrDefault('GOOGLE_VEO_MODEL', 'veo-3.1-generate-preview'),
    'strict_model' => (bool) env('GOOGLE_VEO_STRICT_MODEL', true),
    'video_seconds' => (int) env('GOOGLE_VEO_VIDEO_SECONDS', 8),
    'video_ratio' => $envOrDefault('GOOGLE_VEO_VIDEO_RATIO', '9:16'),
    'resolution' => $envOrDefault('GOOGLE_VEO_RESOLUTION', '720p'),
    'person_generation_text' => $envOrDefault('GOOGLE_VEO_PERSON_GENERATION_TEXT', 'allow_all'),
    'person_generation_image' => $envOrDefault('GOOGLE_VEO_PERSON_GENERATION_IMAGE', 'allow_adult'),
    'negative_prompt' => $envOrDefault('GOOGLE_VEO_NEGATIVE_PROMPT', ''),
    'generate_audio' => (bool) env('GOOGLE_VEO_GENERATE_AUDIO', false),
    'max_prompt_chars' => (int) env('GOOGLE_VEO_MAX_PROMPT_CHARS', 1600),

    'timeout_create' => (int) env('GOOGLE_VEO_TIMEOUT_CREATE', 60),
    'timeout_poll' => (int) env('GOOGLE_VEO_TIMEOUT_POLL', 60),
    'timeout_download' => (int) env('GOOGLE_VEO_TIMEOUT_DOWNLOAD', 240),
    'connect_timeout' => (int) env('GOOGLE_VEO_CONNECT_TIMEOUT', 15),

    'poll_interval' => (int) env('GOOGLE_VEO_VIDEO_POLL_INTERVAL', 10),
    'poll_timeout' => (int) env('GOOGLE_VEO_VIDEO_POLL_TIMEOUT', 900),
];
