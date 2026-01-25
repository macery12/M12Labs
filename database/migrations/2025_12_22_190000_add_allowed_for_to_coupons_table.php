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
                // Add is_active column if it doesn't exist (for databases that don't have it)
                if (!Schema::hasColumn('coupons', 'is_active')) {
                    // Position after expires_at if it exists, otherwise just add it
                    if (Schema::hasColumn('coupons', 'expires_at')) {
                        $table->boolean('is_active')->default(true)->after('expires_at');
                    } else {
                        $table->boolean('is_active')->default(true);
                    }
                }
                
                // Add allowed_for column
                if (!Schema::hasColumn('coupons', 'allowed_for')) {
                    $table->enum('allowed_for', ['both', 'purchases', 'renewals'])->default('both')->after('is_active');
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
