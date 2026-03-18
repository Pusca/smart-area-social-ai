<?php

return [
    'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io'),
    'api_key' => env('ELEVENLABS_API_KEY'),
    'model_id' => env('ELEVENLABS_MODEL_ID', 'eleven_multilingual_v2'),
    'output_format' => env('ELEVENLABS_OUTPUT_FORMAT', 'mp3_44100_128'),
    'timeout' => (int) env('ELEVENLABS_TIMEOUT', 90),
    'connect_timeout' => (int) env('ELEVENLABS_CONNECT_TIMEOUT', 15),
    'stability' => (float) env('ELEVENLABS_VOICE_STABILITY', 0.45),
    'similarity_boost' => (float) env('ELEVENLABS_VOICE_SIMILARITY_BOOST', 0.78),
    'style' => (float) env('ELEVENLABS_VOICE_STYLE', 0.15),
    'use_speaker_boost' => (bool) env('ELEVENLABS_USE_SPEAKER_BOOST', true),
    'clone_description' => env('ELEVENLABS_CLONE_DESCRIPTION', 'Voce persona caricata dal Brand Center per voiceover reel'),
];