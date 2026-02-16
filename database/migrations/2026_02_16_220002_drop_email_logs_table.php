<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration drops the old email_logs table as part of the 
     * refactor to the new email_deliveries + email_delivery_attempts architecture.
     */
    public function up(): void
    {
        Schema::dropIfExists('email_logs');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // If we need to rollback, we would recreate the old structure
        // However, since this is a major refactor, rollback is not practical
        // Data would need to be migrated back from the new tables
        throw new \Exception('Cannot rollback email_logs drop - use migrate:fresh instead');
    }
};
