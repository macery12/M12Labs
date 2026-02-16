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
        Schema::table('email_logs', function (Blueprint $table) {
            // Add status_code field for provider validation errors (e.g., Resend 422)
            $table->unsignedInteger('status_code')->nullable()->after('error');
            
            // Add unique constraint on (provider, message_id) to prevent duplicate logs
            // Note: This is a partial unique index that allows NULL message_id values
            // Multiple rows can have NULL message_id, but once set, the combination must be unique
            $table->unique(['provider', 'message_id'], 'email_logs_provider_message_id_unique');
            
            // Add unique constraint on correlation_id when present
            // This prevents duplicate logs during the early phase before message_id is known
            $table->unique('correlation_id', 'email_logs_correlation_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropUnique('email_logs_provider_message_id_unique');
            $table->dropUnique('email_logs_correlation_id_unique');
            $table->dropColumn('status_code');
        });
    }
};
