<?php

namespace Everest\Services\Email;

use Everest\Mail;
use Everest\Events\Email;

/**
 * The built-in email types: which event sends which Mailable, how each is
 * labelled, and which ones cannot be switched off.
 */
final class EmailCatalogue
{
    /**
     * Mailable class => [event, category, name, description, locked].
     *
     * Locked types ignore their toggle. Password reset and verification are
     * how people get back into, or into, their accounts; switching them off
     * silently broke account recovery.
     */
    private const TYPES = [
        Mail\AccountCreatedMail::class => [Email\AccountCreated::class, 'auth', 'Account Created', 'Welcome email sent when a new account is created', false],
        Mail\EmailVerificationMail::class => [Email\EmailVerificationRequested::class, 'auth', 'Email Verification', 'Email verification link sent to new users', true],
        Mail\PasswordResetMail::class => [Email\PasswordResetRequested::class, 'auth', 'Password Reset Request', 'Password reset link sent when requested', true],
        Mail\PasswordChangedMail::class => [Email\PasswordChanged::class, 'auth', 'Password Successfully Changed', 'Confirmation email after password change', false],
        Mail\NewLoginMail::class => [Email\NewLoginDetected::class, 'auth', 'New Login Detected', 'Alert for new login from unrecognized device/location', false],
        Mail\AccountLockedMail::class => [Email\AccountLocked::class, 'auth', 'Account Locked/Suspended', 'Notification when account is locked or suspended', false],
        Mail\AccountUnsuspendedMail::class => [Email\AccountUnsuspended::class, 'auth', 'Account Unsuspended', 'Notification when account is unsuspended', false],
        Mail\TwoFactorEnabledMail::class => [Email\TwoFactorEnabled::class, 'auth', '2FA Enabled', 'Confirmation when two-factor authentication is enabled', false],
        Mail\TwoFactorDisabledMail::class => [Email\TwoFactorDisabled::class, 'auth', '2FA Disabled', 'Alert when two-factor authentication is disabled', false],
        Mail\ServerCreatedMail::class => [Email\ServerCreatedEmail::class, 'server', 'Server Created', 'Notification when a new server is created', false],
        Mail\ServerSuspendedMail::class => [Email\ServerSuspended::class, 'server', 'Server Suspended', 'Notification when a server is suspended', false],
        Mail\ServerUnsuspendedMail::class => [Email\ServerUnsuspended::class, 'server', 'Server Unsuspended', 'Notification when a server is unsuspended', false],
        Mail\PaymentReceivedMail::class => [Email\PaymentReceived::class, 'billing', 'Payment Received', 'Confirmation when payment is successfully processed', false],
        Mail\PaymentFailedMail::class => [Email\PaymentFailed::class, 'billing', 'Payment Failed', 'Alert when a payment attempt fails', false],
        Mail\ServerRenewalNoticeMail::class => [Email\ServerRenewalNotice::class, 'billing', 'Server Renewal Notice', 'Reminder before server expires with renewal link and suspension time', false],
    ];

    /**
     * @return array<string, EmailType> keyed by template key
     */
    public static function all(): array
    {
        $types = [];

        foreach (self::TYPES as $mailable => [$event, $category, $name, $description, $locked]) {
            $types[$mailable::KEY] = new EmailType($mailable::KEY, $mailable, $event, $category, $name, $description, $locked);
        }

        return $types;
    }

    public static function find(string $key): ?EmailType
    {
        return self::all()[$key] ?? null;
    }

    /**
     * instanceof rather than a class-name lookup, so a subclassed or proxied
     * event still resolves.
     */
    public static function forEvent(object $event): ?EmailType
    {
        foreach (self::all() as $type) {
            if ($event instanceof $type->event) {
                return $type;
            }
        }

        return null;
    }

    public static function isLocked(string $key): bool
    {
        return self::find($key)->locked ?? false;
    }

    /**
     * @return list<class-string>
     */
    public static function events(): array
    {
        return array_column(array_values(self::TYPES), 0);
    }
}
