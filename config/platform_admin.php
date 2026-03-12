<?php

$emails = env('PLATFORM_ADMIN_EMAILS', 'puscastanislav0@gmail.com');

return [
    'authorized_emails' => array_values(array_filter(array_map(
        static fn ($value) => strtolower(trim((string) $value)),
        explode(',', (string) $emails)
    ))),
    'default_email' => env('PLATFORM_ADMIN_EMAIL', 'puscastanislav0@gmail.com'),
    'default_name' => env('PLATFORM_ADMIN_NAME', 'Social AI Admin'),
];
