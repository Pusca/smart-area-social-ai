<?php

return [
    'force_sync' => (bool) env('GENERATION_FORCE_SYNC', false),

    'text_provider_default' => env('TEXT_PROVIDER_DEFAULT', 'openai'),
    'text_providers' => ['openai'],

    'grader_provider_default' => env('GRADER_PROVIDER_DEFAULT', env('TEXT_PROVIDER_DEFAULT', 'openai')),
    'grader_providers' => ['openai'],

    'speech_provider_default' => env('SPEECH_PROVIDER_DEFAULT', 'openai'),
    'speech_providers' => ['openai', 'elevenlabs'],
    'voice_clone_provider_default' => env('VOICE_CLONE_PROVIDER_DEFAULT', 'elevenlabs'),

    'video_provider_default' => env('VIDEO_PROVIDER_DEFAULT', 'runway'),
    'video_providers' => ['openai', 'runway', 'kling'],

    'image_provider_default' => env('IMAGE_PROVIDER_DEFAULT', 'nanobanana'),
    'image_providers' => ['nanobanana', 'openai'],

    'video_auto_audio' => (bool) env('VIDEO_AUTO_AUDIO', true),
    'strict_asset_mode' => (bool) env('GENERATION_STRICT_ASSET_MODE', true),

    'knowledge_pack_examples_limit' => (int) env('GENERATION_KNOWLEDGE_PACK_EXAMPLES_LIMIT', 5),
    'knowledge_pack_negative_examples_limit' => (int) env('GENERATION_KNOWLEDGE_PACK_NEGATIVE_EXAMPLES_LIMIT', 4),

    'alignment_enabled' => (bool) env('GENERATION_ALIGNMENT_ENABLED', true),
    'alignment_text_min_score' => (float) env('GENERATION_ALIGNMENT_TEXT_MIN_SCORE', 0.64),
    'alignment_image_reference_validation' => (bool) env('GENERATION_ALIGNMENT_IMAGE_REFERENCE_VALIDATION', true),
    'alignment_image_reference_min_confidence' => (float) env('GENERATION_ALIGNMENT_IMAGE_REFERENCE_MIN_CONFIDENCE', 0.55),

    'ffmpeg_binary' => env('FFMPEG_BINARY', ''),
    'ffprobe_binary' => env('FFPROBE_BINARY', ''),
];
