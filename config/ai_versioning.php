<?php

return [
    'versions' => [
        'pipeline' => 'generation_pipeline_v1',
        'prompt_template' => 'legacy_inline_prompts_v1',
        'strategy_composer' => 'editorial_strategy_compose_v1',
        'alignment_policy' => 'content_alignment_v1',
        'asset_selection_policy' => 'asset_reference_selection_v1',
        'feedback_synthesis' => 'tenant_feedback_memory_v1',
    ],

    'provider_adapters' => [
        'text' => [
            'openai' => 'openai_text_adapter_v1',
        ],
        'grader' => [
            'openai' => 'openai_grader_adapter_v1',
        ],
        'image' => [
            'openai' => 'openai_image_adapter_v1',
            'nanobanana' => 'nanobanana_image_adapter_v1',
        ],
        'video' => [
            'openai' => 'openai_video_adapter_v1',
            'runway' => 'runway_video_adapter_v1',
            'kling' => 'kling_video_adapter_v1',
        ],
        'speech' => [
            'openai' => 'openai_tts_adapter_v1',
            'elevenlabs' => 'elevenlabs_tts_adapter_v1',
        ],
        'voice_clone' => [
            'elevenlabs' => 'elevenlabs_voice_clone_adapter_v1',
        ],
    ],
];
