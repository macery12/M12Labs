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
        Schema::create('server_workspace_layouts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->char('server_uuid', 36);
            $table->string('layout_key');
            $table->json('layout_json');
            $table->timestamps();

            $table->unique(['user_id', 'server_uuid', 'layout_key'], 'server_workspace_unique');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('server_uuid')->references('uuid')->on('servers')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_workspace_layouts');
    }
};
