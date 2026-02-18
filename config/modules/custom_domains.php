<?php

return [
    'enabled' => env('CUSTOM_DOMAINS_ENABLED', true),

    'cloudflare' => [
        'token' => env('CUSTOM_DOMAINS_CLOUDFLARE_TOKEN', ''),
        'base_url' => env('CUSTOM_DOMAINS_CLOUDFLARE_BASE_URL', 'https://api.cloudflare.com/client/v4'),
        'retries' => (int) env('CUSTOM_DOMAINS_CLOUDFLARE_RETRIES', 3),
        'retry_sleep_ms' => (int) env('CUSTOM_DOMAINS_CLOUDFLARE_RETRY_SLEEP_MS', 250),
        'proxied' => (bool) env('CUSTOM_DOMAINS_CLOUDFLARE_PROXIED', false),
    ],

    'cleanup_on_delete' => (bool) env('CUSTOM_DOMAINS_CLEANUP_ON_DELETE', true),
];
