<?php

return [
    // Senza /v1 finale (il service normalizza in ogni caso)
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
    'api_key' => env('OPENAI_API_KEY'),

    // Copy dei singoli post: veloce ed economico
    'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-5-mini'),

    // Ideazione argomenti del piano: qui la qualità paga, meglio un modello top
    'ideation_model' => env('OPENAI_IDEATION_MODEL', 'gpt-5.1'),

    'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),

    // Secondo pass di revisione editoriale sul copy (raddoppia le chiamate testo)
    'refine_pass' => (bool) env('OPENAI_REFINE_PASS', true),

    // Effort di reasoning per i modelli gpt-5.x / o* ("low" basta per il copy)
    'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),

    // Lingua dei contenuti generati (nome per esteso: "italiano", "English", ...)
    'language' => env('OPENAI_LANGUAGE', 'italiano'),

    'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 2500),

    'timeout' => env('OPENAI_TIMEOUT', 90),
    'timeout_images' => env('OPENAI_TIMEOUT_IMAGES', 120),

    'image_size' => env('OPENAI_IMAGE_SIZE', '1024x1024'),
];
