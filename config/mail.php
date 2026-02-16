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
];
