<?php

return [
    'force_sync' => (bool) env('GENERATION_FORCE_SYNC', false),
    'video_provider_default' => env('VIDEO_PROVIDER_DEFAULT', 'openai'),
    'video_providers' => ['openai', 'runway', 'kling'],
    'image_provider_default' => env('IMAGE_PROVIDER_DEFAULT', 'nanobanana'),
    'image_providers' => ['nanobanana', 'openai'],
    'video_auto_audio' => (bool) env('VIDEO_AUTO_AUDIO', true),
    'strict_asset_mode' => (bool) env('GENERATION_STRICT_ASSET_MODE', true),
    'ffmpeg_binary' => env('FFMPEG_BINARY', ''),
    'ffprobe_binary' => env('FFPROBE_BINARY', ''),
];
