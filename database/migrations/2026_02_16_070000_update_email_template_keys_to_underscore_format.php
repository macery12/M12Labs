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
        // Update email_notification_settings table
        if (Schema::hasTable('email_notification_settings')) {
            DB::statement("
                UPDATE email_notification_settings
                SET template_key = CASE template_key
                    WHEN 'auth.account_created' THEN 'auth_account_created'
                    WHEN 'auth.email_verification' THEN 'auth_email_verification'
                    WHEN 'auth.password_reset' THEN 'auth_password_reset'
                    WHEN 'auth.password_changed' THEN 'auth_password_changed'
                    WHEN 'auth.new_login' THEN 'auth_new_login'
                    WHEN 'auth.account_locked' THEN 'auth_account_locked'
                    WHEN 'auth.account_unsuspended' THEN 'auth_account_unsuspended'
                    WHEN 'auth.2fa_enabled' THEN 'auth_2fa_enabled'
                    WHEN 'auth.2fa_disabled' THEN 'auth_2fa_disabled'
                    WHEN 'server.created' THEN 'server_created'
                    WHEN 'server.suspended' THEN 'server_suspended'
                    WHEN 'server.unsuspended' THEN 'server_unsuspended'
                    WHEN 'server.expiring_soon' THEN 'server_expiring_soon'
                    WHEN 'billing.payment_received' THEN 'billing_payment_received'
                    WHEN 'billing.payment_failed' THEN 'billing_payment_failed'
                    WHEN 'billing.server_renewal_notice' THEN 'billing_server_renewal_notice'
                    ELSE template_key
                END
                WHERE template_key IN (
                    'auth.account_created', 'auth.email_verification', 'auth.password_reset',
                    'auth.password_changed', 'auth.new_login', 'auth.account_locked',
                    'auth.account_unsuspended', 'auth.2fa_enabled', 'auth.2fa_disabled',
                    'server.created', 'server.suspended', 'server.unsuspended', 'server.expiring_soon',
                    'billing.payment_received', 'billing.payment_failed', 'billing.server_renewal_notice'
                )
            ");
        }

        // Update email_logs table
        if (Schema::hasTable('email_logs')) {
            DB::statement("
                UPDATE email_logs
                SET template_key = CASE template_key
                    WHEN 'auth.account_created' THEN 'auth_account_created'
                    WHEN 'auth.email_verification' THEN 'auth_email_verification'
                    WHEN 'auth.password_reset' THEN 'auth_password_reset'
                    WHEN 'auth.password_changed' THEN 'auth_password_changed'
                    WHEN 'auth.new_login' THEN 'auth_new_login'
                    WHEN 'auth.account_locked' THEN 'auth_account_locked'
                    WHEN 'auth.account_unsuspended' THEN 'auth_account_unsuspended'
                    WHEN 'auth.2fa_enabled' THEN 'auth_2fa_enabled'
                    WHEN 'auth.2fa_disabled' THEN 'auth_2fa_disabled'
                    WHEN 'server.created' THEN 'server_created'
                    WHEN 'server.suspended' THEN 'server_suspended'
                    WHEN 'server.unsuspended' THEN 'server_unsuspended'
                    WHEN 'server.expiring_soon' THEN 'server_expiring_soon'
                    WHEN 'billing.payment_received' THEN 'billing_payment_received'
                    WHEN 'billing.payment_failed' THEN 'billing_payment_failed'
                    WHEN 'billing.server_renewal_notice' THEN 'billing_server_renewal_notice'
                    ELSE template_key
                END
                WHERE template_key IN (
                    'auth.account_created', 'auth.email_verification', 'auth.password_reset',
                    'auth.password_changed', 'auth.new_login', 'auth.account_locked',
                    'auth.account_unsuspended', 'auth.2fa_enabled', 'auth.2fa_disabled',
                    'server.created', 'server.suspended', 'server.unsuspended', 'server.expiring_soon',
                    'billing.payment_received', 'billing.payment_failed', 'billing.server_renewal_notice'
                )
            ");
        }

        // Update deferred_emails table
        if (Schema::hasTable('deferred_emails')) {
            DB::statement("
                UPDATE deferred_emails
                SET template_key = CASE template_key
                    WHEN 'auth.account_created' THEN 'auth_account_created'
                    WHEN 'auth.email_verification' THEN 'auth_email_verification'
                    WHEN 'auth.password_reset' THEN 'auth_password_reset'
                    WHEN 'auth.password_changed' THEN 'auth_password_changed'
                    WHEN 'auth.new_login' THEN 'auth_new_login'
                    WHEN 'auth.account_locked' THEN 'auth_account_locked'
                    WHEN 'auth.account_unsuspended' THEN 'auth_account_unsuspended'
                    WHEN 'auth.2fa_enabled' THEN 'auth_2fa_enabled'
                    WHEN 'auth.2fa_disabled' THEN 'auth_2fa_disabled'
                    WHEN 'server.created' THEN 'server_created'
                    WHEN 'server.suspended' THEN 'server_suspended'
                    WHEN 'server.unsuspended' THEN 'server_unsuspended'
                    WHEN 'server.expiring_soon' THEN 'server_expiring_soon'
                    WHEN 'billing.payment_received' THEN 'billing_payment_received'
                    WHEN 'billing.payment_failed' THEN 'billing_payment_failed'
                    WHEN 'billing.server_renewal_notice' THEN 'billing_server_renewal_notice'
                    ELSE template_key
                END
                WHERE template_key IN (
                    'auth.account_created', 'auth.email_verification', 'auth.password_reset',
                    'auth.password_changed', 'auth.new_login', 'auth.account_locked',
                    'auth.account_unsuspended', 'auth.2fa_enabled', 'auth.2fa_disabled',
                    'server.created', 'server.suspended', 'server.unsuspended', 'server.expiring_soon',
                    'billing.payment_received', 'billing.payment_failed', 'billing.server_renewal_notice'
                )
            ");
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
        // Revert email_notification_settings table
        if (Schema::hasTable('email_notification_settings')) {
            DB::statement("
                UPDATE email_notification_settings
                SET template_key = CASE template_key
                    WHEN 'auth_account_created' THEN 'auth.account_created'
                    WHEN 'auth_email_verification' THEN 'auth.email_verification'
                    WHEN 'auth_password_reset' THEN 'auth.password_reset'
                    WHEN 'auth_password_changed' THEN 'auth.password_changed'
                    WHEN 'auth_new_login' THEN 'auth.new_login'
                    WHEN 'auth_account_locked' THEN 'auth.account_locked'
                    WHEN 'auth_account_unsuspended' THEN 'auth.account_unsuspended'
                    WHEN 'auth_2fa_enabled' THEN 'auth.2fa_enabled'
                    WHEN 'auth_2fa_disabled' THEN 'auth.2fa_disabled'
                    WHEN 'server_created' THEN 'server.created'
                    WHEN 'server_suspended' THEN 'server.suspended'
                    WHEN 'server_unsuspended' THEN 'server.unsuspended'
                    WHEN 'server_expiring_soon' THEN 'server.expiring_soon'
                    WHEN 'billing_payment_received' THEN 'billing.payment_received'
                    WHEN 'billing_payment_failed' THEN 'billing.payment_failed'
                    WHEN 'billing_server_renewal_notice' THEN 'billing.server_renewal_notice'
                    ELSE template_key
                END
                WHERE template_key IN (
                    'auth_account_created', 'auth_email_verification', 'auth_password_reset',
                    'auth_password_changed', 'auth_new_login', 'auth_account_locked',
                    'auth_account_unsuspended', 'auth_2fa_enabled', 'auth_2fa_disabled',
                    'server_created', 'server_suspended', 'server_unsuspended', 'server_expiring_soon',
                    'billing_payment_received', 'billing_payment_failed', 'billing_server_renewal_notice'
                )
            ");
        }

        // Revert email_logs table
        if (Schema::hasTable('email_logs')) {
            DB::statement("
                UPDATE email_logs
                SET template_key = CASE template_key
                    WHEN 'auth_account_created' THEN 'auth.account_created'
                    WHEN 'auth_email_verification' THEN 'auth.email_verification'
                    WHEN 'auth_password_reset' THEN 'auth.password_reset'
                    WHEN 'auth_password_changed' THEN 'auth.password_changed'
                    WHEN 'auth_new_login' THEN 'auth.new_login'
                    WHEN 'auth_account_locked' THEN 'auth.account_locked'
                    WHEN 'auth_account_unsuspended' THEN 'auth.account_unsuspended'
                    WHEN 'auth_2fa_enabled' THEN 'auth.2fa_enabled'
                    WHEN 'auth_2fa_disabled' THEN 'auth.2fa_disabled'
                    WHEN 'server_created' THEN 'server.created'
                    WHEN 'server_suspended' THEN 'server.suspended'
                    WHEN 'server_unsuspended' THEN 'server.unsuspended'
                    WHEN 'server_expiring_soon' THEN 'server.expiring_soon'
                    WHEN 'billing_payment_received' THEN 'billing.payment_received'
                    WHEN 'billing_payment_failed' THEN 'billing.payment_failed'
                    WHEN 'billing_server_renewal_notice' THEN 'billing.server_renewal_notice'
                    ELSE template_key
                END
                WHERE template_key IN (
                    'auth_account_created', 'auth_email_verification', 'auth_password_reset',
                    'auth_password_changed', 'auth_new_login', 'auth_account_locked',
                    'auth_account_unsuspended', 'auth_2fa_enabled', 'auth_2fa_disabled',
                    'server_created', 'server_suspended', 'server_unsuspended', 'server_expiring_soon',
                    'billing_payment_received', 'billing_payment_failed', 'billing_server_renewal_notice'
                )
            ");
        }

        // Revert deferred_emails table
        if (Schema::hasTable('deferred_emails')) {
            DB::statement("
                UPDATE deferred_emails
                SET template_key = CASE template_key
                    WHEN 'auth_account_created' THEN 'auth.account_created'
                    WHEN 'auth_email_verification' THEN 'auth.email_verification'
                    WHEN 'auth_password_reset' THEN 'auth.password_reset'
                    WHEN 'auth_password_changed' THEN 'auth.password_changed'
                    WHEN 'auth_new_login' THEN 'auth.new_login'
                    WHEN 'auth_account_locked' THEN 'auth.account_locked'
                    WHEN 'auth_account_unsuspended' THEN 'auth.account_unsuspended'
                    WHEN 'auth_2fa_enabled' THEN 'auth.2fa_enabled'
                    WHEN 'auth_2fa_disabled' THEN 'auth.2fa_disabled'
                    WHEN 'server_created' THEN 'server.created'
                    WHEN 'server_suspended' THEN 'server.suspended'
                    WHEN 'server_unsuspended' THEN 'server.unsuspended'
                    WHEN 'server_expiring_soon' THEN 'server.expiring_soon'
                    WHEN 'billing_payment_received' THEN 'billing.payment_received'
                    WHEN 'billing_payment_failed' THEN 'billing.payment_failed'
                    WHEN 'billing_server_renewal_notice' THEN 'billing.server_renewal_notice'
                    ELSE template_key
                END
                WHERE template_key IN (
                    'auth_account_created', 'auth_email_verification', 'auth_password_reset',
                    'auth_password_changed', 'auth_new_login', 'auth_account_locked',
                    'auth_account_unsuspended', 'auth_2fa_enabled', 'auth_2fa_disabled',
                    'server_created', 'server_suspended', 'server_unsuspended', 'server_expiring_soon',
                    'billing_payment_received', 'billing_payment_failed', 'billing_server_renewal_notice'
                )
            ");
        }
    }
};
