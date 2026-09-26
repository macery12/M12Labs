<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Custom links gain an operator-chosen order and a placement. Before this they
 * listed in creation order and only ever rendered in the dashboard sidebar,
 * never on server pages where users actually spend their time.
 *
 * `placement` is one of everywhere / dashboard / server (inlined here rather
 * than read from CustomLink — migrations must not reference app classes).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('custom_links', function (Blueprint $table): void {
            $table->unsignedInteger('sort')->default(0)->after('visible');
            $table->string('placement', 16)->default('everywhere')->after('sort');
        });

        // Keep the order existing panels already show: creation order.
        DB::table('custom_links')->update(['sort' => DB::raw('id')]);
    }

    public function down(): void
    {
        Schema::table('custom_links', function (Blueprint $table): void {
            $table->dropColumn(['sort', 'placement']);
        });
    }
};
