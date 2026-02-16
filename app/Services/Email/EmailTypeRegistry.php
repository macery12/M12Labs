<?php

namespace Everest\Services\Email;

use Everest\Events\Email\AccountCreated;
use Everest\Events\Email\AccountLocked;
use Everest\Events\Email\AccountUnsuspended;
use Everest\Events\Email\PasswordResetRequested;
use Everest\Events\Email\PasswordChanged;
use Everest\Events\Email\NewLoginDetected;
use Everest\Events\Email\ServerCreatedEmail;
use Everest\Events\Email\ServerSuspended;
use Everest\Events\Email\ServerUnsuspended;
use Everest\Events\Email\TwoFactorEnabled;
use Everest\Events\Email\TwoFactorDisabled;
use Everest\Events\Email\PaymentReceived;
use Everest\Events\Email\PaymentFailed;
use Everest\Events\Email\ServerRenewalNotice;

class EmailTypeRegistry
{
    /**
     * Map event classes to template keys.
     */
    private const EVENT_TO_TEMPLATE = [
        AccountCreated::class => 'auth_account_created',
        AccountLocked::class => 'auth_account_locked',
        AccountUnsuspended::class => 'auth_account_unsuspended',
        PasswordResetRequested::class => 'auth_password_reset',
        PasswordChanged::class => 'auth_password_changed',
        NewLoginDetected::class => 'auth_new_login',
        TwoFactorEnabled::class => 'auth_2fa_enabled',
        TwoFactorDisabled::class => 'auth_2fa_disabled',
        ServerCreatedEmail::class => 'server_created',
        ServerSuspended::class => 'server_suspended',
        ServerUnsuspended::class => 'server_unsuspended',
        PaymentReceived::class => 'billing_payment_received',
        PaymentFailed::class => 'billing_payment_failed',
        ServerRenewalNotice::class => 'billing_server_renewal_notice',
    ];

    /**
     * Define allowed variables for each template.
     * This is a security allowlist - only these variables can be passed to templates.
     */
    private const TEMPLATE_VARIABLES = [
        'auth_account_created' => ['userName', 'userEmail', 'loginUrl'],
        'auth_account_locked' => ['userName', 'reason', 'suspendedAt', 'supportUrl'],
        'auth_account_unsuspended' => ['userName', 'unsuspendedAt'],
        'auth_password_reset' => ['userName', 'resetUrl', 'expiresIn'],
        'auth_password_changed' => ['userName', 'changedAt', 'ipAddress'],
        'auth_new_login' => ['userName', 'ipAddress', 'userAgent', 'location', 'loginTime'],
        'auth_2fa_enabled' => ['userName', 'enabledAt'],
        'auth_2fa_disabled' => ['userName', 'disabledAt', 'ipAddress'],
        'server_created' => ['userName', 'serverName', 'serverId', 'serverUrl', 'nodeLocation'],
        'server_suspended' => ['userName', 'serverName', 'reason', 'suspendedAt'],
        'server_unsuspended' => ['userName', 'serverName', 'unsuspendedAt'],
        'server_expiring_soon' => ['userName', 'serverName', 'expiresAt', 'daysRemaining'],
        'billing_payment_received' => ['userName', 'amount', 'currency', 'paymentMethod', 'invoiceId', 'transactionDate', 'isRenewal', 'originalAmount', 'discountAmount', 'couponCode', 'billingDays', 'billingCycle'],
        'billing_payment_failed' => ['userName', 'amount', 'currency', 'reason', 'invoiceId', 'retryUrl', 'paymentMethod', 'isRenewal'],
        'billing_server_renewal_notice' => ['userName', 'serverName', 'renewalUrl', 'renewalDate', 'suspensionTime', 'renewalAmount', 'currency', 'billingDays', 'billingCycle'],
    ];

    /**
     * Get template key for an event.
     * Uses instanceof to handle proxied events correctly.
     */
    public static function getTemplateKey(object $event): ?string
    {
        // Use instanceof instead of get_class() to handle proxied/queued events
        // Laravel often wraps events in proxies (e.g., PasswordResetRequested_Proxy)
        // which would fail exact class name matching
        foreach (self::EVENT_TO_TEMPLATE as $class => $template) {
            if ($event instanceof $class) {
                return $template;
            }
        }
        
        return null;
    }

    /**
     * Get allowed variables for a template.
     * Normalizes the template key to underscore format.
     * 
     * This method accepts both dot notation (legacy) and underscore notation:
     * - 'auth.password_reset' → normalized to 'auth_password_reset'
     * - 'auth_password_reset' → stays as 'auth_password_reset'
     */
    public static function getAllowedVariables(string $templateKey): array
    {
        // Normalize to underscore format (dots to underscores)
        $normalizedKey = str_replace('.', '_', $templateKey);
        
        // Use the constant directly (issue was in getTemplateKey, not here)
        return self::TEMPLATE_VARIABLES[$normalizedKey] ?? [];
    }

    /**
     * Validate that data contains only allowed variables.
     * Returns array of [valid_data, errors].
     * 
     * Normalizes template key to underscore format for consistent validation.
     * Error messages intentionally use the normalized key to reflect the internal format.
     */
    public static function validateVariables(string $templateKey, array $data): array
    {
        // Normalize template key to underscore format
        // Error messages will use this normalized format for consistency
        $normalizedKey = str_replace('.', '_', $templateKey);
        
        $allowed = self::getAllowedVariables($normalizedKey);
        $errors = [];
        $validData = [];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                $errors[] = "Variable '$key' is not allowed for template '$normalizedKey'";
            } else {
                $validData[$key] = $value;
            }
        }

        // Check for missing required variables (basic ones)
        $required = ['userName']; // userName is required for all templates
        foreach ($required as $req) {
            if (!array_key_exists($req, $data)) {
                $errors[] = "Required variable '$req' is missing for template '$normalizedKey'";
            }
        }

        return [$validData, $errors];
    }

    /**
     * Extract data from an event for template rendering.
     */
    public static function extractDataFromEvent(object $event): array
    {
        $data = [];
        $eventClass = get_class($event);

        switch ($eventClass) {
            case AccountCreated::class:
                /** @var AccountCreated $event */
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'userEmail' => $event->user->email,
                    'loginUrl' => url('/auth/login'),
                ];
                break;

            case AccountLocked::class:
                /** @var AccountLocked $event */
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'reason' => $event->reason,
                    'suspendedAt' => now()->format('F j, Y g:i A'),
                    'supportUrl' => url('/support'),
                ];
                break;

            case AccountUnsuspended::class:
                /** @var AccountUnsuspended $event */
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'unsuspendedAt' => now()->format('F j, Y g:i A'),
                ];
                break;

            case PasswordResetRequested::class:
                /** @var PasswordResetRequested $event */
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'resetUrl' => $event->resetUrl,
                    'expiresIn' => '60 minutes',
                ];
                break;

            case PasswordChanged::class:
                /** @var PasswordChanged $event */
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'changedAt' => now()->format('F j, Y g:i A'),
                    'ipAddress' => request()->ip() ?? 'Unknown',
                ];
                break;

            case NewLoginDetected::class:
                /** @var NewLoginDetected $event */
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'ipAddress' => $event->ipAddress,
                    'userAgent' => $event->userAgent,
                    'location' => 'Unknown', // Could integrate with IP geolocation service
                    'loginTime' => now()->format('F j, Y g:i A'),
                ];
                break;

            case TwoFactorEnabled::class:
                /** @var TwoFactorEnabled $event */
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'enabledAt' => now()->format('F j, Y g:i A'),
                ];
                break;

            case TwoFactorDisabled::class:
                /** @var TwoFactorDisabled $event */
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'disabledAt' => now()->format('F j, Y g:i A'),
                    'ipAddress' => request()->ip() ?? 'Unknown',
                ];
                break;

            case ServerCreatedEmail::class:
                /** @var ServerCreatedEmail $event */
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'serverName' => $event->server->name,
                    'serverId' => $event->server->uuidShort,
                    'serverUrl' => url("/server/{$event->server->uuidShort}"),
                    'nodeLocation' => $event->server->node->name ?? 'Unknown',
                ];
                break;

            case ServerSuspended::class:
                /** @var ServerSuspended $event */
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'serverName' => $event->server->name,
                    'reason' => $event->reason,
                    'suspendedAt' => now()->format('F j, Y g:i A'),
                ];
                break;

            case ServerUnsuspended::class:
                /** @var ServerUnsuspended $event */
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'serverName' => $event->server->name,
                    'unsuspendedAt' => now()->format('F j, Y g:i A'),
                ];
                break;

            case PaymentReceived::class:
                /** @var PaymentReceived $event */
                $billingCycle = self::formatBillingCycle($event->billingDays);
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'amount' => number_format($event->amount, 2),
                    'currency' => strtoupper($event->currency),
                    'paymentMethod' => $event->paymentMethod,
                    'invoiceId' => $event->invoiceId ?? 'N/A',
                    'transactionDate' => now()->format('F j, Y g:i A'),
                    'isRenewal' => $event->isRenewal,
                    'originalAmount' => $event->originalAmount ? number_format($event->originalAmount, 2) : null,
                    'discountAmount' => $event->discountAmount ? number_format($event->discountAmount, 2) : null,
                    'couponCode' => $event->couponCode,
                    'billingDays' => $event->billingDays,
                    'billingCycle' => $billingCycle,
                ];
                break;

            case PaymentFailed::class:
                /** @var PaymentFailed $event */
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'amount' => number_format($event->amount, 2),
                    'currency' => strtoupper($event->currency),
                    'reason' => $event->reason,
                    'invoiceId' => $event->invoiceId ?? 'N/A',
                    'retryUrl' => url('/billing'),
                    'paymentMethod' => $event->paymentMethod,
                    'isRenewal' => $event->isRenewal,
                ];
                break;

            case ServerRenewalNotice::class:
                /** @var ServerRenewalNotice $event */
                $billingCycle = self::formatBillingCycle($event->billingDays);
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'serverName' => $event->server->name,
                    'renewalUrl' => $event->renewalUrl,
                    'renewalDate' => $event->renewalDate,
                    'suspensionTime' => $event->suspensionTime,
                    'renewalAmount' => number_format($event->renewalAmount, 2),
                    'currency' => strtoupper($event->currency),
                    'billingDays' => $event->billingDays,
                    'billingCycle' => $billingCycle,
                ];
                break;
        }

        return $data;
    }

    /**
     * Get recipient email from event.
     */
    public static function getRecipient(object $event): ?string
    {
        // Most events have a user property
        if (property_exists($event, 'user') && $event->user) {
            return $event->user->email;
        }

        return null;
    }

    /**
     * Format billing cycle days into human-readable text.
     */
    private static function formatBillingCycle(?int $days): string
    {
        if (!$days) {
            return 'One-time';
        }

        // Common billing cycles
        if ($days === 1) {
            return 'Daily';
        } elseif ($days === 7) {
            return 'Weekly';
        } elseif ($days === 14) {
            return 'Bi-weekly';
        } elseif ($days === 30) {
            return 'Monthly';
        } elseif ($days === 60) {
            return 'Bi-monthly';
        } elseif ($days === 90) {
            return 'Quarterly';
        } elseif ($days === 180) {
            return 'Semi-annually';
        } elseif ($days === 365) {
            return 'Annually';
        } else {
            // For custom periods, return as "{X} days"
            return $days . ' days';
        }
    }

    /**
     * Get correlation ID from event.
     */
    public static function getCorrelationId(object $event): ?string
    {
        return property_exists($event, 'correlationId') ? $event->correlationId : null;
    }

    /**
     * Get all registered event-to-template mappings.
     */
    public static function getAllMappings(): array
    {
        return self::EVENT_TO_TEMPLATE;
    }
}
