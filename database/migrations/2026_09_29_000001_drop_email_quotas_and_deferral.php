<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * The panel no longer throttles its own mail: per-user allowances, the
 * mirrored Resend plan limits and the deferred-send queue are gone from the
 * code, and a message the provider refuses is retried by the job instead of
 * parked. This removes what they left behind.
 *
 * Mail still waiting in deferred_emails is dropped with the table, not sent
 * and not logged, and so are the delivery-log rows that only said it was
 * waiting. The same goes for the server.expiring_soon toggle, which nothing
 * ever sent.
 *
 * Ordered cheapest first with the drops last, and every step checks before
 * it acts: MySQL commits DDL implicitly, so a run that stops part way must be
 * able to go again. No down(): the data is gone and nothing reads it.
 */
return new class () extends Migration {
    public function up(): void
    {
        DB::table('settings')->whereIn('key', [
            'settings::modules:email:resend:plan',
            'settings::modules:email:resend:custom_monthly_limit',
            'settings::modules:email:resend:custom_daily_limit',
        ])->delete();

        DB::table('email_notification_settings')->where('template_key', 'server.expiring_soon')->delete();

        // Attempts go first rather than through the cascade, which SQLite
        // only honours with foreign keys switched on.
        $deferred = DB::table('email_deliveries')->where('status', 'deferred')->select('id');
        DB::table('email_delivery_attempts')->whereIn('delivery_id', $deferred)->delete();
        DB::table('email_deliveries')->where('status', 'deferred')->delete();

        if (Schema::hasIndex('email_notification_settings', 'email_notification_settings_tenant_id_index')) {
            Schema::table('email_notification_settings', function (Blueprint $table): void {
                $table->dropIndex('email_notification_settings_tenant_id_index');
            });
        }

        foreach (['tenant_id', 'rate_limit_exempt'] as $column) {
            if (Schema::hasColumn('email_notification_settings', $column)) {
                Schema::table('email_notification_settings', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }

        Schema::dropIfExists('deferred_emails');
        Schema::dropIfExists('email_quotas');
        Schema::dropIfExists('resend_quotas');
    }

    public function down(): void
    {
    }
};
