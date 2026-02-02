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
        Schema::table('orders', function (Blueprint $table) {
            // Store the billing cycle days at the time of purchase
            $table->integer('billing_days')->nullable()->after('product_id');
            
            // Store the final calculated price (after multiplier and coupon)
            $table->decimal('final_price', 10, 2)->nullable()->after('billing_days');
            
            // Store the multiplier used for this order
            $table->decimal('multiplier_used', 5, 4)->nullable()->after('final_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['billing_days', 'final_price', 'multiplier_used']);
        });
    }
};
