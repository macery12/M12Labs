<?php

return [
    /*
     * Gate whether runtime API documentation is available.
     * Enabled by default in non-production environments.
     */
    'enabled' => env('API_DOCS_ENABLED', true),

    /*
     * If true, requests must come from an authenticated admin user.
     */
    'admin_only' => env('API_DOCS_ADMIN_ONLY', true),

    /*
     * Include daemon / remote endpoints in the generated spec.
     */
    'include_remote_routes' => env('API_DOCS_INCLUDE_REMOTE', false),

    'cache' => [
        'enabled' => env('API_DOCS_CACHE_ENABLED', true),
        'ttl' => env('API_DOCS_CACHE_TTL', 3600),
        'store' => env('API_DOCS_CACHE_STORE', 'file'),
    ],
];
