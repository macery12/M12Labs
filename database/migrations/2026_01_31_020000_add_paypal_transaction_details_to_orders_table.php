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
        Schema::table('orders', function (Blueprint $table) {
            // PayPal capture/transaction ID - Required for refunds
            $table->string('paypal_capture_id')->nullable()->after('paypal_order_id');
            
            // Payer information for customer support and verification
            $table->string('paypal_payer_id')->nullable()->after('paypal_capture_id');
            $table->string('paypal_payer_email')->nullable()->after('paypal_payer_id');
            
            // Payment status from PayPal for reconciliation
            $table->string('paypal_status')->nullable()->after('paypal_payer_email');
            
            // Actual amount and currency charged by PayPal for verification
            $table->decimal('paypal_amount', 10, 2)->nullable()->after('paypal_status');
            $table->string('paypal_currency', 3)->nullable()->after('paypal_amount');
            
            // When the payment was captured by PayPal
            $table->timestamp('paypal_captured_at')->nullable()->after('paypal_currency');
            
            // Index on capture ID for fast lookups when processing refunds
            $table->index('paypal_capture_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['paypal_capture_id']);
            $table->dropColumn([
                'paypal_capture_id',
                'paypal_payer_id', 
                'paypal_payer_email',
                'paypal_status',
                'paypal_amount',
                'paypal_currency',
                'paypal_captured_at',
            ]);
        });
    }
};
