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
        Schema::create('billing_cycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id');
            $table->integer('days'); // Billing cycle length in days
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            // Ensure unique billing cycles per product
            $table->unique(['product_id', 'days']);
            
            // Foreign key constraint
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_cycles');
    }
};
