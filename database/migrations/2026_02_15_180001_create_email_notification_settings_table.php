<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->string('template_key')->unique();
            $table->boolean('enabled')->default(true);
            $table->string('category')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('rate_limit_exempt')->default(false);
            $table->timestamps();
            
            $table->index(['category', 'enabled']);
        });

        // Insert default settings for all email types
        DB::table('email_notification_settings')->insert([
            // Auth/Security emails
            [
                'template_key' => 'auth.account_created',
                'enabled' => true,
                'category' => 'auth',
                'name' => 'Account Created',
                'description' => 'Welcome email sent when a new account is created',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.email_verification',
                'enabled' => true,
                'category' => 'auth',
                'name' => 'Email Verification',
                'description' => 'Email verification link sent to new users',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.password_reset',
                'enabled' => true,
                'category' => 'auth',
                'name' => 'Password Reset Request',
                'description' => 'Password reset link sent when requested',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.password_changed',
                'enabled' => true,
                'category' => 'auth',
                'name' => 'Password Successfully Changed',
                'description' => 'Confirmation email after password change',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.new_login',
                'enabled' => true,
                'category' => 'auth',
                'name' => 'New Login Detected',
                'description' => 'Alert for new login from unrecognized device/location',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.account_locked',
                'enabled' => true,
                'category' => 'auth',
                'name' => 'Account Locked/Suspended',
                'description' => 'Notification when account is locked or suspended',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.account_unsuspended',
                'enabled' => true,
                'category' => 'auth',
                'name' => 'Account Unsuspended',
                'description' => 'Notification when account is unsuspended',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.2fa_enabled',
                'enabled' => true,
                'category' => 'auth',
                'name' => '2FA Enabled',
                'description' => 'Confirmation when two-factor authentication is enabled',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.2fa_disabled',
                'enabled' => true,
                'category' => 'auth',
                'name' => '2FA Disabled',
                'description' => 'Alert when two-factor authentication is disabled',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Server emails
            [
                'template_key' => 'server.created',
                'enabled' => true,
                'category' => 'server',
                'name' => 'Server Created',
                'description' => 'Notification when a new server is created',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'server.suspended',
                'enabled' => true,
                'category' => 'server',
                'name' => 'Server Suspended',
                'description' => 'Notification when a server is suspended',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'server.unsuspended',
                'enabled' => true,
                'category' => 'server',
                'name' => 'Server Unsuspended',
                'description' => 'Notification when a server is unsuspended',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'server.expiring_soon',
                'enabled' => false,
                'category' => 'server',
                'name' => 'Server Expiring Soon',
                'description' => 'Alert when server is approaching expiration',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Billing emails
            [
                'template_key' => 'billing.payment_received',
                'enabled' => true,
                'category' => 'billing',
                'name' => 'Payment Received',
                'description' => 'Confirmation when payment is successfully processed',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'billing.payment_failed',
                'enabled' => true,
                'category' => 'billing',
                'name' => 'Payment Failed',
                'description' => 'Alert when a payment attempt fails',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'billing.server_renewal_notice',
                'enabled' => true,
                'category' => 'billing',
                'name' => 'Server Renewal Notice',
                'description' => 'Reminder before server expires with renewal link and suspension time',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_notification_settings');
    }
};
