<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as AWS and more. This file provides the de facto location for this type
    | of information, allowing packages to have a conventional file to locate
    | the various service credentials.
    |
    */

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe
    |--------------------------------------------------------------------------
    |
    | Webhook verification credentials for the custom Stripe webhook handler.
    | The API keys themselves are stored in the database (via the Settings model)
    | and fall back to the billing module config — not here.
    |
    | See docs/TODO/CONFIG_REFACTOR.md for the planned migration of all Stripe
    | credentials into this block.
    |
    */

    'stripe' => [
        'webhook_secret'    => env('STRIPE_WEBHOOK_SECRET'),
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
    ],
];
