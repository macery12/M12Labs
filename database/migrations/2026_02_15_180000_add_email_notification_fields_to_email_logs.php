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
            $table->string('template_key')->nullable()->after('subject');
            $table->string('correlation_id')->nullable()->after('template_key');
            $table->string('provider')->default('resend')->after('message_id');
            $table->unsignedBigInteger('user_id')->nullable()->after('to');
            
            $table->index('correlation_id');
            $table->index('template_key');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex(['correlation_id']);
            $table->dropIndex(['template_key']);
            $table->dropIndex(['user_id']);
            
            $table->dropColumn(['template_key', 'correlation_id', 'provider', 'user_id']);
        });
    }
};
