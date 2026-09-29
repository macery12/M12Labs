<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Mail now goes through Laravel's mailers: a primary provider with an
 * optional backup behind it, one sender identity for both, and a delivery
 * log that is pruned. This moves the settings onto that shape and clears the
 * log tables of columns nothing writes any more.
 *
 * Settings:
 * - `transport` becomes `primary`.
 * - Each provider had its own From/name/reply-to. Failover hands one message
 *   to the backup, so there is one set now, taken from the provider that was
 *   active (the other provider's value only where the active one is blank).
 *   The per-provider keys are deleted after the copy.
 * - `resend:enabled` was an old name for the delivery switch; it is copied to
 *   `enabled` when that is missing, then deleted.
 * - Password reset and email verification can no longer be switched off, so
 *   their toggles are turned back on.
 *
 * Delivery log: the id and status-code pairs the old tracker filled twice keep
 * one column each (backfilled first), and tenant_id, metadata, tags and the
 * payload columns go.
 *
 * Ordered cheapest first with the drops last, and every step checks before it
 * acts: MySQL commits DDL implicitly, so a run that stops part way must be
 * able to go again. No down(): the dropped columns held nothing that is read.
 */
return new class () extends Migration {
    private const PREFIX = 'settings::modules:email:';

    public function up(): void
    {
        $this->moveSettings();

        DB::table('email_notification_settings')
            ->whereIn('template_key', ['auth.password_reset', 'auth.email_verification'])
            ->update(['enabled' => true]);

        if (!Schema::hasColumn('email_delivery_attempts', 'provider')) {
            Schema::table('email_delivery_attempts', function (Blueprint $table): void {
                $table->string('provider')->nullable()->after('attempt_number');
            });
        }

        if (Schema::hasColumn('email_deliveries', 'last_message_id')) {
            DB::table('email_deliveries')
                ->whereNull('provider_message_id')
                ->whereNotNull('last_message_id')
                ->update(['provider_message_id' => DB::raw('last_message_id')]);
        }

        if (Schema::hasColumn('email_delivery_attempts', 'response_code')) {
            DB::table('email_delivery_attempts')
                ->whereNull('status_code')
                ->whereNotNull('response_code')
                ->update(['status_code' => DB::raw('response_code')]);
        }

        foreach (['email_deliveries_tenant_id_index', 'email_deliveries_recipient_email_index'] as $index) {
            if (Schema::hasIndex('email_deliveries', $index)) {
                Schema::table('email_deliveries', function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }
        }

        $this->dropColumns('email_deliveries', ['tenant_id', 'recipient_email', 'metadata', 'last_message_id', 'tags']);
        $this->dropColumns('email_delivery_attempts', ['response_code', 'error_message', 'raw_response', 'response_payload', 'request_payload']);
    }

    public function down(): void
    {
    }

    private function moveSettings(): void
    {
        if (!$this->has('enabled') && $this->has('resend:enabled')) {
            $this->put('enabled', (string) $this->value('resend:enabled'));
        }

        // Some very old installs stored the transport without the prefix.
        $transport = $this->value('transport') ?? DB::table('settings')->where('key', 'modules:email:transport')->value('value');
        $active = in_array(strtolower((string) $transport), ['smtp', 'resend'], true) ? strtolower((string) $transport) : 'smtp';

        if (!$this->has('primary') && $transport !== null) {
            $this->put('primary', $active);
        }

        $active = $this->has('primary') ? strtolower((string) $this->value('primary')) : $active;
        $other = $active === 'resend' ? 'smtp' : 'resend';

        foreach (['from_email', 'from_name', 'reply_to'] as $field) {
            if ($this->has($field)) {
                continue;
            }

            $value = trim((string) $this->value("{$active}:{$field}")) ?: trim((string) $this->value("{$other}:{$field}"));

            if ($value !== '') {
                $this->put($field, $value);
            }
        }

        DB::table('settings')->whereIn('key', [
            self::PREFIX . 'transport',
            'modules:email:transport',
            self::PREFIX . 'resend:enabled',
            self::PREFIX . 'smtp:from_email',
            self::PREFIX . 'smtp:from_name',
            self::PREFIX . 'smtp:reply_to',
            self::PREFIX . 'resend:from_email',
            self::PREFIX . 'resend:from_name',
            self::PREFIX . 'resend:reply_to',
        ])->delete();
    }

    private function has(string $key): bool
    {
        return DB::table('settings')->where('key', self::PREFIX . $key)->exists();
    }

    private function value(string $key): mixed
    {
        return DB::table('settings')->where('key', self::PREFIX . $key)->value('value');
    }

    private function put(string $key, string $value): void
    {
        DB::table('settings')->insert(['key' => self::PREFIX . $key, 'value' => $value]);
    }

    /**
     * @param list<string> $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
