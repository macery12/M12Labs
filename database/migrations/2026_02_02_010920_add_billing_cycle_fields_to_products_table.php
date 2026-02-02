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
        Schema::table('products', function (Blueprint $table) {
            // Add base_price as the canonical 30-day price
            // Keep existing 'price' field for backward compatibility
            $table->decimal('base_price', 10, 2)->nullable()->after('price');
            
            // Multiplier for billing cycles > 30 days (e.g., 0.85 for 15% discount)
            $table->decimal('multiplier_up', 5, 4)->default(1.0)->after('base_price');
            
            // Multiplier for billing cycles < 30 days (e.g., 1.25 for 25% premium)
            $table->decimal('multiplier_down', 5, 4)->default(1.0)->after('multiplier_up');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['base_price', 'multiplier_up', 'multiplier_down']);
        });
    }
};
