<?php

namespace Everest\Http\Controllers\Api\Application;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Everest\Http\Requests\Api\Application\Email\GetEmailTemplateKeysRequest;
use Everest\Http\Requests\Api\Application\Email\PreviewEmailTemplateRequest;

class EmailTemplateController extends ApplicationApiController
{
    /**
     * All available email templates with their display metadata and Blade view path.
     */
    private const TEMPLATES = [
        'auth.account_created' => [
            'label'    => 'Account Created',
            'category' => 'Auth',
            'view'     => 'emails.auth.account-created',
        ],
        'auth.account_locked' => [
            'label'    => 'Account Locked / Suspended',
            'category' => 'Auth',
            'view'     => 'emails.auth.account-locked',
        ],
        'auth.account_unsuspended' => [
            'label'    => 'Account Unsuspended',
            'category' => 'Auth',
            'view'     => 'emails.auth.account-unsuspended',
        ],
        'auth.email_verification' => [
            'label'    => 'Email Verification',
            'category' => 'Auth',
            'view'     => 'emails.auth.email-verification',
        ],
        'auth.password_reset' => [
            'label'    => 'Password Reset',
            'category' => 'Auth',
            'view'     => 'emails.auth.password-reset',
        ],
        'auth.password_changed' => [
            'label'    => 'Password Changed',
            'category' => 'Auth',
            'view'     => 'emails.auth.password-changed',
        ],
        'auth.new_login' => [
            'label'    => 'New Login Detected',
            'category' => 'Auth',
            'view'     => 'emails.auth.new-login',
        ],
        'auth.2fa_enabled' => [
            'label'    => 'Two-Factor Auth Enabled',
            'category' => 'Auth',
            'view'     => 'emails.auth.2fa-enabled',
        ],
        'auth.2fa_disabled' => [
            'label'    => 'Two-Factor Auth Disabled',
            'category' => 'Auth',
            'view'     => 'emails.auth.2fa-disabled',
        ],
        'server.created' => [
            'label'    => 'Server Created',
            'category' => 'Server',
            'view'     => 'emails.server.created',
        ],
        'server.suspended' => [
            'label'    => 'Server Suspended',
            'category' => 'Server',
            'view'     => 'emails.server.suspended',
        ],
        'server.unsuspended' => [
            'label'    => 'Server Unsuspended',
            'category' => 'Server',
            'view'     => 'emails.server.unsuspended',
        ],
        'server.expiring_soon' => [
            'label'    => 'Server Expiring Soon',
            'category' => 'Server',
            'view'     => 'emails.server.expiring-soon',
        ],
        'billing.payment_received' => [
            'label'    => 'Payment Received',
            'category' => 'Billing',
            'view'     => 'emails.billing.payment-received',
        ],
        'billing.payment_failed' => [
            'label'    => 'Payment Failed',
            'category' => 'Billing',
            'view'     => 'emails.billing.payment-failed',
        ],
        'billing.server_renewal_notice' => [
            'label'    => 'Server Renewal Notice',
            'category' => 'Billing',
            'view'     => 'emails.billing.server-renewal-notice',
        ],
        'admin.broadcast' => [
            'label'    => 'Admin Broadcast',
            'category' => 'Admin',
            'view'     => 'emails.admin-broadcast',
        ],
    ];

    /**
     * Realistic sample data keyed by template key.
     */
    private const SAMPLE_DATA = [
        'auth.account_created' => [
            'userName'  => 'Jane Smith',
            'userEmail' => 'jane@example.com',
            'loginUrl'  => '#preview-login',
        ],
        'auth.account_locked' => [
            'userName'    => 'Jane Smith',
            'reason'      => 'Multiple failed login attempts detected from suspicious IP addresses.',
            'suspendedAt' => 'April 13, 2026 10:30 AM',
            'supportUrl'  => '#preview-support',
        ],
        'auth.account_unsuspended' => [
            'userName'      => 'Jane Smith',
            'unsuspendedAt' => 'April 13, 2026 2:15 PM',
        ],
        'auth.email_verification' => [
            'userName'        => 'Jane Smith',
            'verificationUrl' => '#preview-verify',
            'expiresIn'       => '60 minutes',
        ],
        'auth.password_reset' => [
            'userName'  => 'Jane Smith',
            'resetUrl'  => '#preview-reset',
            'expiresIn' => '60 minutes',
        ],
        'auth.password_changed' => [
            'userName'  => 'Jane Smith',
            'changedAt' => 'April 13, 2026 11:45 AM',
            'ipAddress' => '192.0.2.42',
        ],
        'auth.new_login' => [
            'userName'  => 'Jane Smith',
            'ipAddress' => '192.0.2.42',
            'userAgent' => 'Chrome 124 on macOS Sonoma',
            'location'  => 'San Francisco, CA, US',
            'loginTime' => 'April 13, 2026 9:00 AM UTC',
        ],
        'auth.2fa_enabled' => [
            'userName'  => 'Jane Smith',
            'enabledAt' => 'April 13, 2026 8:55 AM',
        ],
        'auth.2fa_disabled' => [
            'userName'   => 'Jane Smith',
            'disabledAt' => 'April 13, 2026 8:55 AM',
            'ipAddress'  => '192.0.2.42',
        ],
        'server.created' => [
            'userName'     => 'Jane Smith',
            'serverName'   => 'Survival-Minecraft',
            'serverId'     => 'a1b2c3d4',
            'serverUrl'    => '#preview-server',
            'nodeLocation' => 'US East (New York)',
        ],
        'server.suspended' => [
            'userName'    => 'Jane Smith',
            'serverName'  => 'Survival-Minecraft',
            'reason'      => 'Payment overdue — renewal invoice unpaid for more than 3 days.',
            'suspendedAt' => 'April 13, 2026 12:00 PM',
        ],
        'server.unsuspended' => [
            'userName'      => 'Jane Smith',
            'serverName'    => 'Survival-Minecraft',
            'unsuspendedAt' => 'April 13, 2026 3:30 PM',
        ],
        'server.expiring_soon' => [
            'userName'     => 'Jane Smith',
            'serverName'   => 'Survival-Minecraft',
            'expiresAt'    => 'April 16, 2026 12:00 PM',
            'daysRemaining' => 3,
        ],
        'billing.payment_received' => [
            'userName'        => 'Jane Smith',
            'amount'          => '9.99',
            'currency'        => 'USD',
            'paymentMethod'   => 'Visa •••• 4242',
            'invoiceId'       => 'INV-2026-04289',
            'transactionDate' => 'April 13, 2026 10:00 AM',
            'isRenewal'       => true,
            'originalAmount'  => '12.99',
            'discountAmount'  => '3.00',
            'couponCode'      => 'SAVE3',
            'billingDays'     => 30,
            'billingCycle'    => 'Monthly',
        ],
        'billing.payment_failed' => [
            'userName'      => 'Jane Smith',
            'amount'        => '9.99',
            'currency'      => 'USD',
            'reason'        => 'Your card was declined. Please check your card details or try a different payment method.',
            'invoiceId'     => 'INV-2026-04289',
            'retryUrl'      => '#preview-billing',
            'paymentMethod' => 'Visa •••• 4242',
            'isRenewal'     => false,
        ],
        'billing.server_renewal_notice' => [
            'userName'      => 'Jane Smith',
            'serverName'    => 'Survival-Minecraft',
            'renewalUrl'    => '#preview-renew',
            'renewalDate'   => 'April 16, 2026',
            'suspensionTime' => 'April 16, 2026 12:00 PM UTC',
            'renewalAmount' => '9.99',
            'currency'      => 'USD',
            'billingDays'   => 30,
            'billingCycle'  => 'Monthly',
        ],
        'admin.broadcast' => [
            'adminName' => 'Admin',
            'message'   => "We will be performing scheduled maintenance on April 15, 2026 from 2:00 AM to 4:00 AM UTC.\n\nDuring this window, servers may experience brief interruptions. No data will be lost.\n\nThank you for your patience.",
        ],
    ];

    /**
     * Return the list of available email templates grouped by category.
     */
    public function index(GetEmailTemplateKeysRequest $request): JsonResponse
    {
        $templates = [];
        foreach (self::TEMPLATES as $key => $meta) {
            $templates[] = [
                'key'      => $key,
                'label'    => $meta['label'],
                'category' => $meta['category'],
            ];
        }

        return response()->json(['templates' => $templates]);
    }

    /**
     * Render a single template with sample data and return raw HTML.
     * No email is sent — this is a read-only preview.
     */
    public function preview(PreviewEmailTemplateRequest $request, string $key): Response
    {
        $meta = self::TEMPLATES[$key] ?? null;

        if ($meta === null) {
            abort(404, 'Template not found.');
        }

        $data = self::SAMPLE_DATA[$key] ?? [];

        $html = view($meta['view'], $data)->render();

        return response($html, 200, [
            'Content-Type'           => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'SAMEORIGIN',
        ]);
    }
}
