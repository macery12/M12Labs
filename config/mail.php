<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Email Logging Debug Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, detailed debug information will be stored in email
    | delivery attempts, including request/response payloads and stack traces.
    | This is useful for debugging but should be disabled in production.
    |
    */
    'log_debug' => env('EMAIL_LOG_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Markdown Mail Settings
    |--------------------------------------------------------------------------
    |
    | Register the default markdown mail view paths so Blade components like
    | "mail::message" can be resolved when rendering email templates.
    |
    */
    'markdown' => [
        'theme' => 'default',
        'paths' => [
            resource_path('views/vendor/mail'),
        ],
    ],
];
