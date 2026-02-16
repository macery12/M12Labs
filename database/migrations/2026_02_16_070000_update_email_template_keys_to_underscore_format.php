<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration converts existing email template keys from dot notation (e.g., 'auth.password_reset')
     * to underscore notation (e.g., 'auth_password_reset') to fix ASCII encoding issues.
     */
    public function up(): void
    {
        // Mapping of old (dot notation) to new (underscore notation) template keys
        $keyMappings = [
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

        // Update email_notification_settings table
        if (Schema::hasTable('email_notification_settings')) {
            foreach ($keyMappings as $oldKey => $newKey) {
                DB::table('email_notification_settings')
                    ->where('template_key', $oldKey)
                    ->update(['template_key' => $newKey]);
            }
        }

        // Update email_logs table
        if (Schema::hasTable('email_logs')) {
            foreach ($keyMappings as $oldKey => $newKey) {
                DB::table('email_logs')
                    ->where('template_key', $oldKey)
                    ->update(['template_key' => $newKey]);
            }
        }

        // Update deferred_emails table
        if (Schema::hasTable('deferred_emails')) {
            foreach ($keyMappings as $oldKey => $newKey) {
                DB::table('deferred_emails')
                    ->where('template_key', $oldKey)
                    ->update(['template_key' => $newKey]);
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * This migration is not reversible because we don't want to revert to the problematic dot notation.
     * If absolutely necessary to rollback, you should manually restore from a backup.
     */
    public function down(): void
    {
        // Mapping of new (underscore notation) to old (dot notation) template keys
        $keyMappings = [
            'auth_account_created' => 'auth.account_created',
            'auth_email_verification' => 'auth.email_verification',
            'auth_password_reset' => 'auth.password_reset',
            'auth_password_changed' => 'auth.password_changed',
            'auth_new_login' => 'auth.new_login',
            'auth_account_locked' => 'auth.account_locked',
            'auth_account_unsuspended' => 'auth.account_unsuspended',
            'auth_2fa_enabled' => 'auth.2fa_enabled',
            'auth_2fa_disabled' => 'auth.2fa_disabled',
            'server_created' => 'server.created',
            'server_suspended' => 'server.suspended',
            'server_unsuspended' => 'server.unsuspended',
            'server_expiring_soon' => 'server.expiring_soon',
            'billing_payment_received' => 'billing.payment_received',
            'billing_payment_failed' => 'billing.payment_failed',
            'billing_server_renewal_notice' => 'billing.server_renewal_notice',
        ];

        // Revert email_notification_settings table
        if (Schema::hasTable('email_notification_settings')) {
            foreach ($keyMappings as $newKey => $oldKey) {
                DB::table('email_notification_settings')
                    ->where('template_key', $newKey)
                    ->update(['template_key' => $oldKey]);
            }
        }

        // Revert email_logs table
        if (Schema::hasTable('email_logs')) {
            foreach ($keyMappings as $newKey => $oldKey) {
                DB::table('email_logs')
                    ->where('template_key', $newKey)
                    ->update(['template_key' => $oldKey]);
            }
        }

        // Revert deferred_emails table
        if (Schema::hasTable('deferred_emails')) {
            foreach ($keyMappings as $newKey => $oldKey) {
                DB::table('deferred_emails')
                    ->where('template_key', $newKey)
                    ->update(['template_key' => $oldKey]);
            }
        }
    }
};
