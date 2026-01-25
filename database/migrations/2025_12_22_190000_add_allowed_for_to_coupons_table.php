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
                // Ensure all required columns from create_coupons_table exist
                // This handles cases where the table was created manually or partially
                
                if (!Schema::hasColumn('coupons', 'code')) {
                    $table->string('code')->unique();
                }
                
                if (!Schema::hasColumn('coupons', 'type')) {
                    $table->enum('type', ['percentage', 'fixed']);
                }
                
                if (!Schema::hasColumn('coupons', 'value')) {
                    $table->decimal('value', 10, 2);
                }
                
                if (!Schema::hasColumn('coupons', 'max_uses')) {
                    $table->integer('max_uses')->nullable();
                }
                
                if (!Schema::hasColumn('coupons', 'max_uses_per_user')) {
                    $table->integer('max_uses_per_user')->nullable();
                }
                
                if (!Schema::hasColumn('coupons', 'min_order_total')) {
                    $table->decimal('min_order_total', 10, 2)->nullable();
                }
                
                if (!Schema::hasColumn('coupons', 'expires_at')) {
                    $table->dateTime('expires_at')->nullable();
                }
                
                if (!Schema::hasColumn('coupons', 'is_active')) {
                    $table->boolean('is_active')->default(true);
                }
                
                if (!Schema::hasColumn('coupons', 'created_at')) {
                    $table->timestamps();
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
