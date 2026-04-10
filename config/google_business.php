<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Business Profile OAuth2 Credentials
    |--------------------------------------------------------------------------
    |
    | Credenziali dell'app Google registrata su console.cloud.google.com.
    | API da abilitare: My Business Business Information API + My Business Management API
    | Scope richiesto: https://www.googleapis.com/auth/business.manage
    |
    */
    'client_id'     => env('GOOGLE_BUSINESS_CLIENT_ID', ''),
    'client_secret' => env('GOOGLE_BUSINESS_CLIENT_SECRET', ''),
    'redirect_uri'  => env('GOOGLE_BUSINESS_REDIRECT_URI', ''),

    /*
    |--------------------------------------------------------------------------
    | OAuth Scopes
    |--------------------------------------------------------------------------
    */
    'scopes' => [
        'https://www.googleapis.com/auth/business.manage',
    ],

    /*
    |--------------------------------------------------------------------------
    | Local Post Settings
    |--------------------------------------------------------------------------
    |
    | language_code: codice lingua BCP-47 dei local post (es. 'it', 'en').
    | Influenza come Google interpreta il contenuto ai fini dell'indicizzazione.
    |
    */
    'language_code' => env('GOOGLE_BUSINESS_LANGUAGE_CODE', 'it'),

    /*
    |--------------------------------------------------------------------------
    | Publish Queue
    |--------------------------------------------------------------------------
    */
    'publish_queue' => env('GOOGLE_BUSINESS_PUBLISH_QUEUE', 'social-publish'),
];
