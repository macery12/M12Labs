<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * A modpack install records whether the user asked for the loader step. The
 * queue's retry used to re-run it unconditionally, so retrying a pack that
 * was installed onto an existing loader rewrote the server's startup command
 * and Docker image anyway.
 *
 * Existing rows default to false: a retry of one of them no longer touches
 * the loader, which is the safe reading of "we don't know".
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('download_queue', function (Blueprint $table): void {
            $table->boolean('install_loader')->default(false)->after('phase');
        });
    }

    public function down(): void
    {
        Schema::table('download_queue', function (Blueprint $table): void {
            $table->dropColumn('install_loader');
        });
    }
};
