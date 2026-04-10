<?php

return [
    /*
    |--------------------------------------------------------------------------
    | LinkedIn OAuth2 Credentials
    |--------------------------------------------------------------------------
    |
    | Credenziali dell'app LinkedIn registrata su developer.linkedin.com.
    | Scope richiesti: openid, profile, w_member_social, w_organization_social
    |
    */
    'client_id'     => env('LINKEDIN_CLIENT_ID', ''),
    'client_secret' => env('LINKEDIN_CLIENT_SECRET', ''),
    'redirect_uri'  => env('LINKEDIN_REDIRECT_URI', ''),

    /*
    |--------------------------------------------------------------------------
    | LinkedIn API Version
    |--------------------------------------------------------------------------
    |
    | Versione dell'API LinkedIn da usare nell'header LinkedIn-Version.
    | Formato: YYYYMM. Consultare le release notes LinkedIn per aggiornamenti.
    |
    */
    'api_version' => env('LINKEDIN_API_VERSION', '202401'),

    /*
    |--------------------------------------------------------------------------
    | OAuth Scopes
    |--------------------------------------------------------------------------
    */
    'scopes' => [
        'openid',               // ID token (per profilo)
        'profile',              // Nome e cognome
        'w_member_social',      // Pubblica post come persona
        'w_organization_social', // Pubblica post come pagina aziendale
    ],

    /*
    |--------------------------------------------------------------------------
    | Publish Queue
    |--------------------------------------------------------------------------
    */
    'publish_queue' => env('LINKEDIN_PUBLISH_QUEUE', 'social-publish'),
];
