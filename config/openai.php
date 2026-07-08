<?php

return [
    // Senza /v1 finale (il service normalizza in ogni caso)
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
    'api_key' => env('OPENAI_API_KEY'),

    'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-4.1-mini'),
    'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),

    // Lingua dei contenuti generati (nome per esteso: "italiano", "English", ...)
    'language' => env('OPENAI_LANGUAGE', 'italiano'),

    'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 1200),

    'timeout' => env('OPENAI_TIMEOUT', 60),
    'timeout_images' => env('OPENAI_TIMEOUT_IMAGES', 120),

    'image_size' => env('OPENAI_IMAGE_SIZE', '1024x1024'),
];
