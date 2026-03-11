<?php

$envOrDefault = static function (string $key, string $default = ''): string {
    $value = trim((string) env($key, $default));

    return $value !== '' ? $value : $default;
};

return [
    // Default: Google AI Studio Gemini API (Nano Banana).
    'base_url' => $envOrDefault('NANOBANANA_BASE_URL', 'https://generativelanguage.googleapis.com'),
    'api_key' => env('NANOBANANA_API_KEY'),
    'api_version' => $envOrDefault('NANOBANANA_API_VERSION', 'v1beta'),
    'auth_mode' => $envOrDefault('NANOBANANA_AUTH_MODE', 'auto'), // auto|x-goog-api-key|bearer

    // Gemini generateContent endpoint template.
    'generate_endpoint' => $envOrDefault('NANOBANANA_GENERATE_ENDPOINT', '/{version}/models/{model}:generateContent'),
    'response_modalities' => $envOrDefault('NANOBANANA_RESPONSE_MODALITIES', 'IMAGE'),
    'aspect_ratio' => $envOrDefault('NANOBANANA_ASPECT_RATIO', '4:5'),
    'image_size' => env('NANOBANANA_IMAGE_SIZE', ''), // 512px|1K|2K|4K (or legacy WxH, auto-mapped to aspectRatio)

    // Legacy OpenAI-compatible fields (kept for backward compatibility)
    'image_endpoint' => $envOrDefault('NANOBANANA_IMAGE_ENDPOINT', '/v1/images/generations'),
    'image_edit_endpoint' => $envOrDefault('NANOBANANA_IMAGE_EDIT_ENDPOINT', '/v1/images/edits'),

    'image_model' => $envOrDefault('NANOBANANA_IMAGE_MODEL', 'gemini-2.5-flash-image'),

    'timeout' => (int) env('NANOBANANA_TIMEOUT', 120),
    'connect_timeout' => (int) env('NANOBANANA_CONNECT_TIMEOUT', 15),
];
