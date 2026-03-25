<?php

namespace Everest\Services\Email;

class EmailSubjectResolver
{
    private const DELIVERY_SUBJECTS = [
        'auth.account_created' => 'Welcome to Your Account',
        'auth.email_verification' => 'Verify Your Email Address',
        'auth.password_reset' => 'Reset Your Password',
        'auth.password_changed' => 'Your Password Has Been Changed',
        'auth.new_login' => 'New Login Detected',
        'auth.account_locked' => 'Your Account Has Been Suspended',
        'auth.account_unsuspended' => 'Your Account Has Been Restored',
        'auth.2fa_enabled' => 'Two-Factor Authentication Enabled',
        'auth.2fa_disabled' => 'Two-Factor Authentication Disabled',
        'server.created' => 'Your Server Has Been Created',
        'server.suspended' => 'Your Server Has Been Suspended',
        'server.unsuspended' => 'Your Server Has Been Unsuspended',
        'server.expiring_soon' => 'Your Server Is Expiring Soon',
        'billing.payment_received' => 'Payment Received - Thank You',
        'billing.payment_failed' => 'Payment Failed - Action Required',
        'billing.server_renewal_notice' => 'Server Renewal Notice - Action Required',
    ];

    private const TRACKING_SUBJECTS = [
        'auth.account_created' => 'Welcome to ',
        'auth.account_locked' => 'Your Account Has Been Locked',
        'auth.account_unsuspended' => 'Your Account Has Been Reactivated',
        'auth.email_verification' => 'Verify Your Email Address',
        'auth.password_reset' => 'Reset Your Password',
        'auth.password_changed' => 'Your Password Has Been Changed',
        'auth.new_login' => 'New Login Detected',
        'auth.2fa_enabled' => 'Two-Factor Authentication Enabled',
        'auth.2fa_disabled' => 'Two-Factor Authentication Disabled',
        'server.created' => 'Your Server Has Been Created',
        'server.suspended' => 'Server Suspended',
        'server.unsuspended' => 'Server Reactivated',
        'billing.payment_received' => 'Payment Received',
        'billing.payment_failed' => 'Payment Failed',
        'billing.server_renewal_notice' => 'Server Renewal Notice',
    ];

    public static function forDelivery(string $templateKey): string
    {
        return self::DELIVERY_SUBJECTS[$templateKey] ?? 'Notification';
    }

    public static function forTracking(string $templateKey): string
    {
        if ($templateKey === 'auth.account_created') {
            return self::TRACKING_SUBJECTS[$templateKey] . config('app.name');
        }

        return self::TRACKING_SUBJECTS[$templateKey] ?? ('Notification from ' . config('app.name'));
    }
}
