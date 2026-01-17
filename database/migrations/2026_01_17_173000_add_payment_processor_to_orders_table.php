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
        Schema::table('billing_orders', function (Blueprint $table) {
            $table->string('payment_processor')->default('stripe')->after('payment_intent_id');
            $table->string('mollie_payment_id')->nullable()->after('payment_processor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_orders', function (Blueprint $table) {
            $table->dropColumn(['payment_processor', 'mollie_payment_id']);
        });
    }
};
