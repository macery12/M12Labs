<?php

namespace Everest\Services\Billing;

use Everest\Models\Setting;

/**
 * Central source of truth for billing configuration defaults.
 *
 * Reads from the admin settings store so that operators can change defaults
 * without a code deploy.  Falls back to 30 days when the setting is absent
 * (first-boot or legacy installs that never set the value).
 */
class BillingDefaults
{
    public static function defaultBillingDays(): int
    {
        return (int) Setting::get(
            'settings::modules:billing:renewal:default_billing_days',
            30
        );
    }
}
