<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('alert_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alert_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->foreign('alert_id')->references('id')->on('alerts')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            $table->unique(['alert_id', 'user_id']);
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->enum('user_targeting', ['all', 'specific'])->default('all')->after('scope');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_user');
        
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn('user_targeting');
        });
    }
};
