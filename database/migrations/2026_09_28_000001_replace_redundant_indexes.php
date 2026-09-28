<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Every index costs a write on every insert and update. The ones dropped here
 * duplicate an index the table already has -- the primary key, a unique index
 * on the same columns, or a composite that starts with them -- so they were
 * paid for on every write and never the better choice for a read. Each drop
 * leaves an index leading with the same column, so no foreign key loses the
 * index InnoDB needs behind it.
 *
 * The additions are for reads that had nothing to use:
 * - orders(status, created_at): the hourly order cleanup selects by status and
 *   age, and no index led with status.
 * - activity_logs(actor_type, actor_id, timestamp) and (server_id, timestamp):
 *   the account and server activity pages filter by owner, then sort newest
 *   first. They replace the single-column versions, which they cover.
 * - server_groups(user_id) and ticket_messages(user_id): per-user lookups on
 *   columns with no index at all.
 * - servers(billing_product_id): the billing overview joins products on it.
 *
 * MySQL commits each ALTER on its own, so every step checks before it acts
 * and a run that stops part way can simply run again. Additions go first, so
 * the activity log is never left without an index on its owner columns.
 */
return new class () extends Migration {
    /** @var list<array{string, string, list<string>}> table, index, columns */
    private const ADDED = [
        ['orders', 'orders_status_created_at_index', ['status', 'created_at']],
        ['activity_logs', 'activity_logs_actor_type_actor_id_timestamp_index', ['actor_type', 'actor_id', 'timestamp']],
        ['activity_logs', 'activity_logs_server_id_timestamp_index', ['server_id', 'timestamp']],
        ['server_groups', 'server_groups_user_id_index', ['user_id']],
        ['ticket_messages', 'ticket_messages_user_id_index', ['user_id']],
        ['servers', 'servers_billing_product_id_index', ['billing_product_id']],
    ];

    /** @var list<array{string, string, list<string>, bool}> table, index, columns, unique */
    private const REDUNDANT = [
        ['admin_roles', 'admin_roles_id_unique', ['id'], true],
        ['mounts', 'mounts_id_unique', ['id'], true],
        ['email_quotas', 'email_quotas_user_id_index', ['user_id'], false],
        ['email_quotas', 'email_quotas_user_id_plan_index', ['user_id', 'plan'], false],
        ['extension_configs', 'extension_configs_extension_id_index', ['extension_id'], false],
        ['payment_transactions', 'payment_transactions_processor_external_id_index', ['processor', 'external_id'], false],
        ['payment_transactions', 'payment_transactions_order_id_index', ['order_id'], false],
        ['orders', 'orders_user_id_index', ['user_id'], false],
        ['deferred_emails', 'deferred_emails_user_id_index', ['user_id'], false],
        ['deferred_emails', 'deferred_emails_sent_at_index', ['sent_at'], false],
        ['email_notification_settings', 'email_notification_settings_category_index', ['category'], false],
        ['extension_operations', 'extension_operations_extension_id_index', ['extension_id'], false],
        ['extension_signature_audit', 'extension_signature_audit_extension_id_index', ['extension_id'], false],
        ['extension_hook_tombstones', 'extension_hook_tombstones_correlation_id_index', ['correlation_id'], false],
        ['activity_logs', 'activity_logs_actor_type_actor_id_index', ['actor_type', 'actor_id'], false],
        ['activity_logs', 'activity_logs_server_id_index', ['server_id'], false],
    ];

    public function up(): void
    {
        foreach (self::ADDED as [$table, $name, $columns]) {
            if (!Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
            }
        }

        foreach (self::REDUNDANT as [$table, $name, , $unique]) {
            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $unique ? $blueprint->dropUnique($name) : $blueprint->dropIndex($name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::REDUNDANT as [$table, $name, $columns, $unique]) {
            if (!Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $unique ? $blueprint->unique($columns, $name) : $blueprint->index($columns, $name));
            }
        }

        foreach (self::ADDED as [$table, $name]) {
            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
    }
};
