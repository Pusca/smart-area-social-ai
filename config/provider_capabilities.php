<?php

return [
    'area_aliases' => [
        'alignment' => 'grader',
    ],

    'areas' => [
        'text' => [
            'default_provider_config' => 'generation.text_provider_default',
            'providers_config' => 'generation.text_providers',
        ],
        'grader' => [
            'default_provider_config' => 'generation.grader_provider_default',
            'providers_config' => 'generation.grader_providers',
        ],
        'image' => [
            'default_provider_config' => 'generation.image_provider_default',
            'providers_config' => 'generation.image_providers',
        ],
        'video' => [
            'default_provider_config' => 'generation.video_provider_default',
            'providers_config' => 'generation.video_providers',
        ],
        'speech' => [
            'default_provider_config' => 'generation.speech_provider_default',
            'providers_config' => 'generation.speech_providers',
        ],
        'voice_clone' => [
            'default_provider_config' => 'generation.voice_clone_provider_default',
            'providers_config' => 'generation.speech_providers',
        ],
    ],

    'providers' => [
        'openai' => [
            'credential_paths' => ['openai.api_key'],
            'areas' => [
                'text' => [
                    'fallbacks' => [],
                    'model_config' => 'openai.text_model',
                    'timeouts' => [
                        'request' => 'openai.timeout',
                    ],
                ],
                'grader' => [
                    'fallbacks' => [],
                    'model_config' => 'openai.text_model',
                    'timeouts' => [
                        'request' => 'openai.timeout',
                    ],
                ],
                'image' => [
                    'reference_aware' => true,
                    'strict_asset_mode' => true,
                    'fallbacks' => ['nanobanana'],
                    'model_config' => 'openai.image_model',
                    'sizes' => ['1024x1024', '1536x1024', '1024x1536'],
                    'aspect_ratios' => ['1:1', '3:2', '2:3'],
                    'timeouts' => [
                        'request' => 'openai.timeout_images',
                    ],
                ],
                'video' => [
                    'reference_aware' => true,
                    'image_to_video' => true,
                    'text_to_video' => true,
                    'native_audio' => false,
                    'requires_mux' => true,
                    'strict_asset_mode' => false,
                    'fallbacks' => ['runway', 'kling'],
                    'model_config' => 'openai.video_model',
                    'model_prefixes' => ['sora-2'],
                    'duration_rule' => [
                        'mode' => 'enum',
                        'values' => [4, 8, 12],
                    ],
                    'sizes' => ['720x1280', '1280x720', '1024x1792', '1792x1024'],
                    'aspect_ratios' => ['9:16', '16:9'],
                    'timeouts' => [
                        'request' => 'openai.timeout_video_create',
                        'poll' => 'openai.video_poll_timeout',
                    ],
                ],
                'speech' => [
                    'native_audio' => true,
                    'requires_mux' => false,
                    'fallbacks' => ['elevenlabs'],
                    'model_config' => 'openai.speech_model',
                    'timeouts' => [
                        'request' => 'openai.timeout_speech',
                    ],
                ],
            ],
        ],
        'runway' => [
            'credential_paths' => ['runway.api_key'],
            'areas' => [
                'video' => [
                    'reference_aware' => true,
                    'image_to_video' => true,
                    'text_to_video' => false,
                    'native_audio' => false,
                    'requires_mux' => true,
                    'strict_asset_mode' => true,
                    'fallbacks' => ['openai', 'kling'],
                    'model_config' => 'runway.model',
                    'model_aliases' => [
                        'gen4_turbo' => 'gen4.5',
                        'gen4-turbo' => 'gen4.5',
                    ],
                    'duration_rules' => [
                        'default' => [
                            'mode' => 'range',
                            'min' => 3,
                            'max' => 10,
                        ],
                        'veo3' => [
                            'mode' => 'enum',
                            'values' => [4, 6, 8],
                        ],
                        'veo3.1' => [
                            'mode' => 'enum',
                            'values' => [4, 6, 8],
                        ],
                        'veo3.1_fast' => [
                            'mode' => 'enum',
                            'values' => [4, 6, 8],
                        ],
                    ],
                    'aspect_ratios' => ['9:16', '16:9', '1:1'],
                    'timeouts' => [
                        'request' => 'runway.timeout_create',
                        'poll' => 'runway.poll_timeout',
                    ],
                ],
            ],
        ],
        'kling' => [
            'credential_paths' => ['kling.access_key', 'kling.secret_key'],
            'areas' => [
                'video' => [
                    'reference_aware' => true,
                    'image_to_video' => true,
                    'text_to_video' => true,
                    'native_audio' => false,
                    'requires_mux' => true,
                    'strict_asset_mode' => true,
                    'fallbacks' => ['runway', 'openai'],
                    'model_config' => 'kling.model',
                    'context_models' => [
                        'text' => 'kling-v3-omni',
                        'image' => 'kling-v3',
                        'multi_image' => 'kling-v3',
                        'fallback' => 'kling-v3',
                    ],
                    'model_prefixes' => ['kling-'],
                    'duration_rules' => [
                        'kling-v3-omni' => [
                            'mode' => 'range',
                            'min' => 3,
                            'max' => 15,
                        ],
                        'kling-v3' => [
                            'mode' => 'range',
                            'min' => 3,
                            'max' => 15,
                        ],
                        'default' => [
                            'mode' => 'enum',
                            'values' => [5, 10],
                        ],
                    ],
                    'aspect_ratios' => ['9:16', '16:9', '1:1'],
                    'timeouts' => [
                        'request' => 'kling.timeout_create',
                        'poll' => 'kling.poll_timeout',
                    ],
                ],
            ],
        ],
        'nanobanana' => [
            'credential_paths' => ['nanobanana.api_key'],
            'areas' => [
                'image' => [
                    'reference_aware' => true,
                    'strict_asset_mode' => true,
                    'fallbacks' => ['openai'],
                    'model_config' => 'nanobanana.image_model',
                    'sizes' => ['512px', '1K', '2K', '4K'],
                    'aspect_ratios' => ['1:1', '4:5', '9:16', '16:9'],
                    'timeouts' => [
                        'request' => 'nanobanana.timeout',
                    ],
                ],
            ],
        ],
        'elevenlabs' => [
            'credential_paths' => ['elevenlabs.api_key'],
            'areas' => [
                'speech' => [
                    'native_audio' => true,
                    'requires_mux' => false,
                    'fallbacks' => ['openai'],
                    'model_config' => 'elevenlabs.model_id',
                    'timeouts' => [
                        'request' => 'elevenlabs.timeout',
                    ],
                ],
                'voice_clone' => [
                    'native_audio' => true,
                    'requires_mux' => false,
                    'fallbacks' => [],
                    'model_config' => 'elevenlabs.model_id',
                    'timeouts' => [
                        'request' => 'elevenlabs.timeout',
                    ],
                ],
            ],
        ],
    ],
];
