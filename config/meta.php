<?php

return [
    'app_id' => env('META_APP_ID'),
    'app_secret' => env('META_APP_SECRET'),
    'graph_base_url' => env('META_GRAPH_BASE_URL', 'https://graph.facebook.com'),
    'graph_version' => env('META_GRAPH_VERSION', 'v22.0'),
    'redirect_uri' => env('META_REDIRECT_URI'),
    'scopes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'META_SCOPES',
            'pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish,business_management'
        ))
    ))),
    'public_media_base_url' => env('SOCIAL_PUBLIC_MEDIA_BASE_URL', ''),
    'allow_local_public_urls' => (bool) env('SOCIAL_ALLOW_LOCAL_PUBLIC_URLS', false),
    'publish_queue' => env('SOCIAL_PUBLISH_QUEUE', 'social-publish'),
    'instagram_container_poll_seconds' => (int) env('META_IG_CONTAINER_POLL_SECONDS', 120),
    'instagram_container_poll_interval' => (int) env('META_IG_CONTAINER_POLL_INTERVAL', 5),
];
