<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        $db = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $db)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }

    public function up(): void
    {
        // Add column only if missing
        Schema::table('email_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('email_logs', 'status_code')) {
                $table->unsignedInteger('status_code')->nullable()->after('error');
            }
        });

        // Add unique index only if missing
        if (!$this->indexExists('email_logs', 'email_logs_provider_message_id_unique')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->unique(['provider', 'message_id'], 'email_logs_provider_message_id_unique');
            });
        }

        // Add unique index only if missing
        if (!$this->indexExists('email_logs', 'email_logs_correlation_id_unique')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->unique('correlation_id', 'email_logs_correlation_id_unique');
            });
        }
    }

    public function down(): void
    {
        // Drop indexes only if they exist (prevents rollback failures)
        if ($this->indexExists('email_logs', 'email_logs_provider_message_id_unique')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->dropUnique('email_logs_provider_message_id_unique');
            });
        }

        if ($this->indexExists('email_logs', 'email_logs_correlation_id_unique')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->dropUnique('email_logs_correlation_id_unique');
            });
        }

        Schema::table('email_logs', function (Blueprint $table) {
            if (Schema::hasColumn('email_logs', 'status_code')) {
                $table->dropColumn('status_code');
            }
        });
    }
};
