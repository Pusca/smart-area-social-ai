<?php

return [
    // IMPORTANTISSIMO: SENZA /v1
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),

    'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-4.1-mini'),
    'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),

    'timeout' => env('OPENAI_TIMEOUT', 60),
    'timeout_images' => env('OPENAI_TIMEOUT_IMAGES', 120),

    'image_size' => env('OPENAI_IMAGE_SIZE', '1024x1024'),
];