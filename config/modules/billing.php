<?php

return [
    /*
     * Enable or disable the billing module.
     */
    'enabled' => env('BILLING_ENABLED', false),

    /*
     * Select the payment processor: 'stripe' or 'mollie'.
     * NOTE: This is deprecated. Payment processors are now managed via
     * the integrations system in the admin panel at /admin/billing/integrations.
     * Kept for backward compatibility.
     */
    'processor' => env('BILLING_PROCESSOR', 'stripe'),

    /*
     * Configure the publishable & secret API key for Stripe.
     */
    'keys' => [
        'publishable' => env('BILLING_PUBLISHABLE_KEY', ''),
        'secret' => env('BILLING_SECRET_KEY', ''),
    ],

    /*
     * Configure the Mollie API key.
     */
    'mollie' => [
        'api_key' => env('MOLLIE_API_KEY', ''),
    ],

    /*
     * Choose whether to add PayPal integration to the Panel (via Stripe).
     * This is for PayPal integration through Stripe's payment methods.
     */
    'paypal' => env('BILLING_PAYPAL', false),

    /*
     * Configure standalone PayPal integration.
     * Credentials are stored in database via Settings model.
     */
    'paypal_standalone' => [
        'client_id' => '',
        'client_secret' => '',
        'mode' => 'sandbox', // 'sandbox' or 'live'
    ],

    /*
     * Choose whether to add Link integration to the Panel.
     */
    'link' => env('BILLING_LINK', false),

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
        'free_renewal_days' => env('BILLING_FREE_RENEWAL_DAYS', 30),
        'suspension_threshold' => env('BILLING_SUSPENSION_THRESHOLD', 7),
        'free_suspension_days' => env('BILLING_FREE_SUSPENSION_DAYS', 7),
        'paid_suspension_days' => env('BILLING_PAID_SUSPENSION_DAYS', 30),
    ],

    /*
     * Configure cooldown period for plan changes (in hours).
     */
    'plan_change_cooldown_hours' => env('BILLING_PLAN_CHANGE_COOLDOWN_HOURS', 72),
];
