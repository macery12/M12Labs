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
        Schema::create('email_quotas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('plan')->default('free'); // free, pro, scale
            $table->integer('monthly_limit')->default(3000);
            $table->integer('daily_limit')->nullable()->default(100);
            $table->integer('monthly_sent')->default(0);
            $table->integer('daily_sent')->default(0);
            $table->integer('monthly_overage')->default(0);
            $table->date('month_reset_at')->default(DB::raw('CURDATE()'));
            $table->date('day_reset_at')->default(DB::raw('CURDATE()'));
            $table->timestamps();
            
            $table->index('user_id');
            $table->index(['user_id', 'plan']);
            $table->index('month_reset_at');
            $table->index('day_reset_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_quotas');
    }
};
