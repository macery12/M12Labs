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
        Schema::table('recovery_tokens', function (Blueprint $table) {
            $table->timestamp('used_at')->nullable()->after('created_at');
            $table->string('last_used_ip', 45)->nullable()->after('used_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recovery_tokens', function (Blueprint $table) {
            $table->dropColumn(['used_at', 'last_used_ip']);
        });
    }
};
