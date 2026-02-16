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
            // Enhanced status tracking
            $table->string('status')->default('sent')->after('success'); // sent, deferred, failed, blocked, skipped
            $table->unsignedInteger('attempt_count')->default(1)->after('status');
            $table->unsignedInteger('duration_ms')->nullable()->after('attempt_count');
            
            // Extended metadata for debugging and provider responses
            $table->json('metadata')->nullable()->after('tags');
            
            // Rendered email content (for preview)
            $table->text('rendered_subject')->nullable()->after('metadata');
            $table->longText('rendered_html')->nullable()->after('rendered_subject');
            $table->longText('rendered_text')->nullable()->after('rendered_html');
            
            // Template variables (sanitized/redacted)
            $table->json('template_variables')->nullable()->after('rendered_text');
            
            // Add indexes for common queries
            $table->index('status');
            $table->index(['user_id', 'created_at']);
            $table->index(['template_key', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['template_key', 'created_at']);
            
            $table->dropColumn([
                'status',
                'attempt_count',
                'duration_ms',
                'metadata',
                'rendered_subject',
                'rendered_html',
                'rendered_text',
                'template_variables',
            ]);
        });
    }
};
