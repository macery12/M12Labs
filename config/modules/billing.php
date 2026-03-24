<?php

return [
    /*
     * Enable or disable the billing module.
     */
    'enabled' => env('BILLING_ENABLED', false),

    /*
     * Configure the secret API key for Stripe.
     */
    'keys' => [
        'secret' => env('BILLING_SECRET_KEY', ''),
    ],

    /*
     * Set a currency code and symbol to use for billing.
     */
    'currency' => [
        'symbol' => '$',
        'code' => 'usd',
    ],

    /*
     * Configure URLs for legal documentation.
     */
    'links' => [
        'terms' => '',
        'privacy' => '',
    ],

    /*
     * Configure renewal and suspension settings.
     */
    'renewal' => [
        'days' => env('BILLING_RENEWAL_DAYS', 30),
        'threshold' => env('BILLING_RENEWAL_THRESHOLD', 7),
    ],

    /*
     * Control whether users should be allowed to upgrade their plan.
     */
    'allow_upgrades' => env('BILLING_ALLOW_UPGRADES', true),
];
