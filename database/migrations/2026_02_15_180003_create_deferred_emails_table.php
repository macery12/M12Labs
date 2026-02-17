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
        Schema::create('deferred_emails', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('template_key');
            $table->string('recipient');
            $table->json('data');
            $table->string('correlation_id')->nullable();
            $table->string('reason'); // 'daily_limit', 'monthly_limit'
            $table->timestamp('scheduled_at');
            $table->timestamp('sent_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('scheduled_at');
            $table->index(['user_id', 'scheduled_at']);
            $table->index('sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deferred_emails');
    }
};
