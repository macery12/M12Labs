<?php

return [
    /*
     * Enable or disable jGuard
     */
    'enabled' => env('JGUARD_ENABLED', false),

    /*
     * Controls how new registrations are handled.
     *
     * - 'manual'   Admin must manually approve each new account.
     * - 'delayed'  Accounts activate automatically after the configured delay.
     * - 'immediate' Accounts are activated as soon as they register (effectively disables gating).
     */
    'approval_mode' => env('JGUARD_APPROVAL_MODE', 'manual'),

    /*
     * Sets a delay in minutes for new user signups when approval_mode is 'delayed'.
     * Users must wait this many minutes before their account becomes active.
     */
    'delay' => env('JGUARD_DELAY', 60),
];
