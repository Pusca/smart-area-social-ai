<?php

return [
    'api_key' => env('OPENAI_API_KEY'),
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

    'text_model'  => env('OPENAI_TEXT_MODEL', env('OPENAI_MODEL', 'gpt-4.1-mini')),

    // Modello immagini: deve essere uno supportato dall’endpoint images/generations
    'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),

    'max_tokens'  => (int) env('OPENAI_MAX_TOKENS', 900),
    'temperature' => (float) env('OPENAI_TEMPERATURE', 0.7),
];
