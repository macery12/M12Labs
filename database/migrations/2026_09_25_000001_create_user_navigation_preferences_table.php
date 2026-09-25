<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Per-user sidebar state: pinned entries and which groups are folded.
 *
 * Its own table rather than columns on `users` so each sidebar area gets a row
 * (only `admin` today; the server sidebar is meant to follow) without widening
 * the hottest table in the panel for a convenience setting.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('user_navigation_preferences', function (Blueprint $table): void {
            $table->bigIncrements('id');
            // users.id is int unsigned; the FK column must match it exactly.
            $table->unsignedInteger('user_id');
            $table->string('area', 32);
            $table->json('pinned');
            $table->json('collapsed');
            $table->timestamps();

            $table->unique(['user_id', 'area']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_navigation_preferences');
    }
};
