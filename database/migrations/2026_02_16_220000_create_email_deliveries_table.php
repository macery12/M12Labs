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
        Schema::create('email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('correlation_id')->unique();
            $table->string('template_key')->nullable();
            $table->string('recipient');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('subject');
            $table->string('provider')->default('resend');
            $table->string('status')->default('queued'); // queued, deferred, sending, sent, failed, skipped, blocked
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_message_id')->nullable();
            $table->unsignedInteger('last_status_code')->nullable();
            $table->text('last_error')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'created_at']);
            $table->index(['template_key', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('recipient');

            // Foreign key
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_deliveries');
    }
};
