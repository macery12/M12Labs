<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

/*
 * Three log tables that nothing in the panel writes or reads:
 * - `api_logs`, an API request log that was never wired up;
 * - `tasks_log`, the run log of the scheduler before its 2017 rewrite;
 * - `audit_logs`, superseded by `activity_logs`.
 *
 * The schema rebuild kept them because the Pterodactyl importer copied them.
 * The importer now lists them as excluded and reports any rows it leaves
 * behind, so nothing else needs them.
 *
 * An install that imported rows into them loses those rows here. No page ever
 * showed them. No down(): nothing reads these tables, so there is nothing to
 * put back.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('api_logs');
        Schema::dropIfExists('tasks_log');
    }

    public function down(): void
    {
    }
};
