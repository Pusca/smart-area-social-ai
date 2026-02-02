<?php

return [
    'api_key' => env('OPENAI_API_KEY'),

    'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-4.1-mini'),
    'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-4.1-mini'),

    'max_tokens' => env('OPENAI_MAX_TOKENS', 900),
    'temperature' => env('OPENAI_TEMPERATURE', 0.7),
];
