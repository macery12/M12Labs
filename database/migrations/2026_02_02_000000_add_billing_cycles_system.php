<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration implements a comprehensive billing cycles system that allows:
     * - Products to have flexible billing cycles (per-product configuration)
     * - Step-based pricing multipliers via global settings
     * - Order history tracking with billing cycle details
     * - Server-level billing cycle assignment
     */
    public function up(): void
    {
        // 1. Add billing cycle support to products table
        Schema::table('products', function (Blueprint $table) {
            // Base price represents the canonical 30-day price
            // The existing 'price' field is kept for backward compatibility
            $table->decimal('base_price', 10, 2)->nullable()->after('price');
        });

        // 2. Create billing_cycles table for per-product cycle configuration
        Schema::create('billing_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->integer('days');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            // Ensure each product can only have one cycle with a specific number of days
            $table->unique(['product_id', 'days']);
        });

        // 3. Add billing cycle tracking to orders table
        Schema::table('orders', function (Blueprint $table) {
            // Store the billing cycle days at the time of purchase (for historical record)
            $table->integer('billing_days')->nullable()->after('product_id');
            
            // Store the final calculated price (after multiplier and coupon application)
            $table->decimal('final_price', 10, 2)->nullable()->after('billing_days');
            
            // Store the multiplier used for this order (for audit trail)
            $table->decimal('multiplier_used', 5, 4)->nullable()->after('final_price');
        });

        // 4. Add billing cycle assignment to servers table
        Schema::table('servers', function (Blueprint $table) {
            // Store which billing cycle this server is currently on
            $table->integer('billing_days')->nullable()->after('billing_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove in reverse order to respect foreign key constraints
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('billing_days');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['billing_days', 'final_price', 'multiplier_used']);
        });

        Schema::dropIfExists('billing_cycles');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('base_price');
        });
    }
};
