<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LEGACY_TO_NORMALIZED = [
        'auth.account_created' => 'auth_account_created',
        'auth.email_verification' => 'auth_email_verification',
        'auth.password_reset' => 'auth_password_reset',
        'auth.password_changed' => 'auth_password_changed',
        'auth.new_login' => 'auth_new_login',
        'auth.account_locked' => 'auth_account_locked',
        'auth.account_unsuspended' => 'auth_account_unsuspended',
        'auth.2fa_enabled' => 'auth_2fa_enabled',
        'auth.2fa_disabled' => 'auth_2fa_disabled',
        'server.created' => 'server_created',
        'server.suspended' => 'server_suspended',
        'server.unsuspended' => 'server_unsuspended',
        'server.expiring_soon' => 'server_expiring_soon',
        'billing.payment_received' => 'billing_payment_received',
        'billing.payment_failed' => 'billing_payment_failed',
        'billing.server_renewal_notice' => 'billing_server_renewal_notice',
    ];

    public function up(): void
    {
        foreach (self::LEGACY_TO_NORMALIZED as $legacy => $normalized) {
            $legacyExists = DB::table('email_notification_settings')
                ->where('template_key', $legacy)
                ->exists();

            if (!$legacyExists) {
                continue;
            }

            $normalizedExists = DB::table('email_notification_settings')
                ->where('template_key', $normalized)
                ->exists();

            if ($normalizedExists) {
                DB::table('email_notification_settings')
                    ->where('template_key', $legacy)
                    ->delete();
            } else {
                DB::table('email_notification_settings')
                    ->where('template_key', $legacy)
                    ->update(['template_key' => $normalized]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::LEGACY_TO_NORMALIZED as $legacy => $normalized) {
            $normalizedExists = DB::table('email_notification_settings')
                ->where('template_key', $normalized)
                ->exists();

            if (!$normalizedExists) {
                continue;
            }

            $legacyExists = DB::table('email_notification_settings')
                ->where('template_key', $legacy)
                ->exists();

            if ($legacyExists) {
                DB::table('email_notification_settings')
                    ->where('template_key', $normalized)
                    ->delete();
            } else {
                DB::table('email_notification_settings')
                    ->where('template_key', $normalized)
                    ->update(['template_key' => $legacy]);
            }
        }
    }
};
