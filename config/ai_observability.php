<?php

return [
    'default_window_days' => 30,

    'pricing' => [
        'text' => [
            'openai' => [
                'models' => [
                    'gpt-4.1-mini' => [
                        'input_per_million_tokens_usd' => 0.40,
                        'output_per_million_tokens_usd' => 1.60,
                    ],
                    'gpt-4o-mini' => [
                        'input_per_million_tokens_usd' => 0.15,
                        'output_per_million_tokens_usd' => 0.60,
                    ],
                ],
                'default' => [
                    'input_per_million_tokens_usd' => 0.40,
                    'output_per_million_tokens_usd' => 1.60,
                ],
            ],
        ],

        'image' => [
            'openai' => [
                'models' => [
                    // Assunzione esplicita: gpt-image-1 a qualita media, in assenza di quality esplicita nel payload.
                    'gpt-image-1' => [
                        'quality' => 'medium',
                        'per_request_usd_by_size' => [
                            '1024x1024' => 0.0420,
                            '1024x1536' => 0.0630,
                            '1536x1024' => 0.0630,
                        ],
                    ],
                ],
                'default_per_request_usd' => 0.0420,
            ],
            'nanobanana' => [
                'models' => [
                    'gemini-2.5-flash-image' => [
                        'per_request_usd' => 0.0390,
                    ],
                ],
                'default_per_request_usd' => 0.0390,
            ],
        ],

        'video' => [
            'openai' => [
                'models' => [
                    'sora-2' => [
                        'per_second_usd' => 0.1000,
                    ],
                    'sora-2-pro' => [
                        'per_second_usd' => 0.3000,
                        'per_second_usd_high_res' => 0.5000,
                    ],
                ],
                'default_per_second_usd' => 0.1000,
            ],
            'runway' => [
                'models' => [
                    'gen4.5' => ['per_second_usd' => 0.1200],
                    'gen4_turbo' => ['per_second_usd' => 0.0500],
                    'gen3a_turbo' => ['per_second_usd' => 0.0500],
                    'act_two' => ['per_second_usd' => 0.0500],
                    'veo3' => ['per_second_usd' => 0.4000],
                    'veo3.1' => ['per_second_usd' => 0.2000],
                    'veo3.1_fast' => ['per_second_usd' => 0.1000],
                ],
                'default_per_second_usd' => 0.1200,
            ],
            'kling' => [
                // Provider con pricing pubblico/API non stabilizzato nel repo: lasciare configurabile.
                'default_per_second_usd' => null,
            ],
        ],

        'speech' => [
            'openai' => [
                'default_per_1k_chars_usd' => 0.0150,
            ],
            'elevenlabs' => [
                // Pricing dipendente dal piano/crediti: lasciare configurabile per il deployment.
                'default_per_1k_chars_usd' => null,
            ],
        ],
    ],
];
