<?php

return [
    /*
    |--------------------------------------------------------------------------
    | TikTok OAuth2 Credentials
    |--------------------------------------------------------------------------
    |
    | Credenziali dell'app TikTok registrata su developers.tiktok.com.
    | Scope richiesti: video.upload, video.publish
    |
    */
    'client_key'    => env('TIKTOK_CLIENT_KEY', ''),
    'client_secret' => env('TIKTOK_CLIENT_SECRET', ''),
    'redirect_uri'  => env('TIKTOK_REDIRECT_URI', ''),

    /*
    |--------------------------------------------------------------------------
    | OAuth Scopes
    |--------------------------------------------------------------------------
    */
    'scopes' => [
        'video.upload',   // Permesso upload video
        'video.publish',  // Permesso pubblicazione video
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Post Settings
    |--------------------------------------------------------------------------
    |
    | Configurazione default per i post TikTok.
    | privacy_level: PUBLIC_TO_EVERYONE | MUTUAL_FOLLOW_FRIENDS | SELF_ONLY
    |
    */
    'default_privacy_level' => env('TIKTOK_DEFAULT_PRIVACY', 'PUBLIC_TO_EVERYONE'),
    'disable_duet'          => (bool) env('TIKTOK_DISABLE_DUET', false),
    'disable_comment'       => (bool) env('TIKTOK_DISABLE_COMMENT', false),
    'disable_stitch'        => (bool) env('TIKTOK_DISABLE_STITCH', false),

    /*
    |--------------------------------------------------------------------------
    | Publish Queue
    |--------------------------------------------------------------------------
    */
    'publish_queue' => env('TIKTOK_PUBLISH_QUEUE', 'social-publish'),
];
