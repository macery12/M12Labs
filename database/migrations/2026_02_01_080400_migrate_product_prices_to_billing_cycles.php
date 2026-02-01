<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create default 30-day billing cycle
        $defaultCycleId = DB::table('billing_cycles')->insertGetId([
            'name' => '30 Days',
            'duration_days' => 30,
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Migrate existing products to use the default 30-day cycle
        $products = DB::table('products')->get();
        
        foreach ($products as $product) {
            DB::table('product_billing_cycles')->insert([
                'product_id' => $product->id,
                'billing_cycle_id' => $defaultCycleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Update existing servers to use the default billing cycle
        DB::table('servers')
            ->whereNotNull('billing_product_id')
            ->update(['billing_cycle_id' => $defaultCycleId]);

        // Update existing orders to use the default billing cycle
        DB::table('orders')
            ->whereNotNull('product_id')
            ->update(['billing_cycle_id' => $defaultCycleId]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Clear product billing cycles
        DB::table('product_billing_cycles')->truncate();
        
        // Remove default billing cycle
        DB::table('billing_cycles')->where('name', '30 Days')->delete();
        
        // Clear billing cycle from servers and orders
        DB::table('servers')->update(['billing_cycle_id' => null]);
        DB::table('orders')->update(['billing_cycle_id' => null]);
    }
};
