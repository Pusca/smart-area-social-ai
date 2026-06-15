<?php

return [
    'base_url' => env('RUNWAY_BASE_URL', 'https://api.dev.runwayml.com'),
    'api_key' => env('RUNWAY_API_KEY'),
    'api_version' => env('RUNWAY_API_VERSION', '2024-11-06'),

    'model' => env('RUNWAY_VIDEO_MODEL', 'gen4.5'),
    'strict_model' => (bool) env('RUNWAY_STRICT_MODEL', false),
    'video_seconds' => (int) env('RUNWAY_VIDEO_SECONDS', 10),
    'video_ratio' => env('RUNWAY_VIDEO_RATIO', '9:16'),
    'max_prompt_chars' => (int) env('RUNWAY_MAX_PROMPT_CHARS', 1400),

    'create_endpoint' => env('RUNWAY_CREATE_ENDPOINT', '/v1/image_to_video'),
    'retrieve_endpoint' => env('RUNWAY_RETRIEVE_ENDPOINT', '/v1/tasks/{id}'),

    'image_model' => env('RUNWAY_IMAGE_MODEL', 'gen4_image'),
    'image_create_endpoint' => env('RUNWAY_IMAGE_CREATE_ENDPOINT', '/v1/text_to_image'),
    'image_ratio' => env('RUNWAY_IMAGE_RATIO', '1:1'),

    'timeout_create' => (int) env('RUNWAY_TIMEOUT_CREATE', 60),
    'timeout_poll' => (int) env('RUNWAY_TIMEOUT_POLL', 60),
    'timeout_download' => (int) env('RUNWAY_TIMEOUT_DOWNLOAD', 240),
    'connect_timeout' => (int) env('RUNWAY_CONNECT_TIMEOUT', 15),

    'poll_interval' => (int) env('RUNWAY_VIDEO_POLL_INTERVAL', 8),
    'poll_timeout' => (int) env('RUNWAY_VIDEO_POLL_TIMEOUT', 420),
    'playback_trim_seconds' => (float) env('RUNWAY_PLAYBACK_TRIM_SECONDS', 0.35),

    // TTS — usa ElevenLabs via Runway (/v1/text_to_speech, model eleven_multilingual_v2)
    // Preset disponibili (49): Maya, Lara, Rachel, Lisa, Miriam, Marlene, Martin, ...
    // Scegliere una voce con accento neutro europeo adatta all'italiano.
    'tts_preset_voice' => env('RUNWAY_TTS_VOICE', 'Lara'),
    'tts_max_chars' => (int) env('RUNWAY_TTS_MAX_CHARS', 900),
];
