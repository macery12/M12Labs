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
        Schema::create('product_billing_cycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('billing_cycle_id');
            $table->decimal('price', 10, 2); // Price for this specific billing cycle
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('billing_cycle_id')->references('id')->on('billing_cycles')->onDelete('cascade');
            
            // Ensure unique combination of product and billing cycle
            $table->unique(['product_id', 'billing_cycle_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_billing_cycles');
    }
};
