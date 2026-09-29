<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Panel mailers
    |--------------------------------------------------------------------------
    |
    | Every message the panel sends goes through the `panel` mailer. Nothing
    | here is the real configuration: providers and the sender are set in
    | Admin -> Email, and PanelMailerConfigurator writes them into these
    | entries at send time. `panel` becomes a copy of the primary provider's
    | mailer, or a `failover` of primary then backup when a backup is set.
    |
    | `panel` is deliberately left undefined until then, so a send that skips
    | the configurator fails loudly instead of disappearing into a log or an
    | array transport.
    |
    */

    'default' => 'panel',

    'mailers' => [
        'panel_smtp' => [
            'transport' => 'smtp',
            'scheme' => null,
            'host' => null,
            'port' => null,
            'username' => null,
            'password' => null,
            'timeout' => 30,
        ],

        'panel_resend' => [
            'transport' => 'resend',
            'key' => null,
        ],
    ],

    'from' => [
        'address' => null,
        'name' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Logging Debug Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, delivery attempts also keep the exception class and stack
    | trace of a failure. Useful for debugging; leave it off in production.
    |
    */
    'log_debug' => env('EMAIL_LOG_DEBUG', false),
];
