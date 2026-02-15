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
        AccountCreated::class => 'auth.account_created',
        AccountLocked::class => 'auth.account_locked',
        AccountUnsuspended::class => 'auth.account_unsuspended',
        PasswordResetRequested::class => 'auth.password_reset',
        PasswordChanged::class => 'auth.password_changed',
        NewLoginDetected::class => 'auth.new_login',
        TwoFactorEnabled::class => 'auth.2fa_enabled',
        TwoFactorDisabled::class => 'auth.2fa_disabled',
        ServerCreatedEmail::class => 'server.created',
        ServerSuspended::class => 'server.suspended',
        ServerUnsuspended::class => 'server.unsuspended',
        PaymentReceived::class => 'billing.payment_received',
        PaymentFailed::class => 'billing.payment_failed',
        ServerRenewalNotice::class => 'billing.server_renewal_notice',
    ];

    /**
     * Define allowed variables for each template.
     * This is a security allowlist - only these variables can be passed to templates.
     */
    private const TEMPLATE_VARIABLES = [
        'auth.account_created' => ['userName', 'userEmail', 'loginUrl'],
        'auth.account_locked' => ['userName', 'reason', 'suspendedAt', 'supportUrl'],
        'auth.account_unsuspended' => ['userName', 'unsuspendedAt'],
        'auth.password_reset' => ['userName', 'resetUrl', 'expiresIn'],
        'auth.password_changed' => ['userName', 'changedAt', 'ipAddress'],
        'auth.new_login' => ['userName', 'ipAddress', 'userAgent', 'location', 'loginTime'],
        'auth.2fa_enabled' => ['userName', 'enabledAt'],
        'auth.2fa_disabled' => ['userName', 'disabledAt', 'ipAddress'],
        'server.created' => ['userName', 'serverName', 'serverId', 'serverUrl', 'nodeLocation'],
        'server.suspended' => ['userName', 'serverName', 'reason', 'suspendedAt'],
        'server.unsuspended' => ['userName', 'serverName', 'unsuspendedAt'],
        'server.expiring_soon' => ['userName', 'serverName', 'expiresAt', 'daysRemaining'],
        'billing.payment_received' => ['userName', 'amount', 'currency', 'paymentMethod', 'invoiceId', 'transactionDate'],
        'billing.payment_failed' => ['userName', 'amount', 'currency', 'reason', 'invoiceId', 'retryUrl'],
        'billing.server_renewal_notice' => ['userName', 'serverName', 'renewalUrl', 'expiresAt', 'suspensionTime', 'renewalAmount', 'currency'],
    ];

    /**
     * Get template key for an event.
     */
    public static function getTemplateKey(object $event): ?string
    {
        $eventClass = get_class($event);
        return self::EVENT_TO_TEMPLATE[$eventClass] ?? null;
    }

    /**
     * Get allowed variables for a template.
     */
    public static function getAllowedVariables(string $templateKey): array
    {
        return self::TEMPLATE_VARIABLES[$templateKey] ?? [];
    }

    /**
     * Validate that data contains only allowed variables.
     * Returns array of [valid_data, errors].
     */
    public static function validateVariables(string $templateKey, array $data): array
    {
        $allowed = self::getAllowedVariables($templateKey);
        $errors = [];
        $validData = [];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                $errors[] = "Variable '$key' is not allowed for template '$templateKey'";
            } else {
                $validData[$key] = $value;
            }
        }

        // Check for missing required variables (basic ones)
        $required = ['userName']; // userName is required for all templates
        foreach ($required as $req) {
            if (!array_key_exists($req, $data)) {
                $errors[] = "Required variable '$req' is missing for template '$templateKey'";
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
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'amount' => number_format($event->amount, 2),
                    'currency' => strtoupper($event->currency),
                    'paymentMethod' => $event->paymentMethod,
                    'invoiceId' => $event->invoiceId ?? 'N/A',
                    'transactionDate' => now()->format('F j, Y g:i A'),
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
                ];
                break;

            case ServerRenewalNotice::class:
                /** @var ServerRenewalNotice $event */
                $data = [
                    'userName' => $event->user->name ?? $event->user->username,
                    'serverName' => $event->server->name,
                    'renewalUrl' => $event->renewalUrl,
                    'expiresAt' => $event->expiresAt,
                    'suspensionTime' => $event->suspensionTime,
                    'renewalAmount' => number_format($event->renewalAmount, 2),
                    'currency' => strtoupper($event->currency),
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
