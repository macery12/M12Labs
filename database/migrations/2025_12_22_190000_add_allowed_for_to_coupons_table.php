<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('coupons')) {
            Schema::table('coupons', function (Blueprint $table) {
                // Add is_active column if it doesn't exist (should be created by create_coupons_table migration)
                // This is a safety measure for databases where the initial migration didn't run properly
                if (!Schema::hasColumn('coupons', 'is_active')) {
                    $table->boolean('is_active')->default(true);
                }
                
                // Add allowed_for column if it doesn't exist
                if (!Schema::hasColumn('coupons', 'allowed_for')) {
                    $table->enum('allowed_for', ['both', 'purchases', 'renewals'])->default('both');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('coupons')) {
            Schema::table('coupons', function (Blueprint $table) {
                if (Schema::hasColumn('coupons', 'allowed_for')) {
                    $table->dropColumn('allowed_for');
                }
            });
        }
    }
};
