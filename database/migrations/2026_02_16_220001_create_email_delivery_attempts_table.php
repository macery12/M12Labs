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
        Schema::create('email_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delivery_id');
            $table->unsignedInteger('attempt_number');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('success')->default(false);
            $table->string('status'); // sending, sent, failed
            $table->string('provider_message_id')->nullable();
            $table->unsignedInteger('status_code')->nullable();
            $table->text('error')->nullable();
            $table->json('request_payload')->nullable(); // only when APP_DEBUG=true
            $table->text('response_payload')->nullable(); // only when APP_DEBUG=true
            $table->string('exception_class')->nullable();
            $table->longText('stacktrace')->nullable(); // only when APP_DEBUG=true
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->unique(['delivery_id', 'attempt_number']);
            $table->index('provider_message_id');

            // Foreign key with cascade delete
            $table->foreign('delivery_id')
                ->references('id')
                ->on('email_deliveries')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_delivery_attempts');
    }
};
