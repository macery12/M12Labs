<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class FixForeignKeyColumnTypes extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration fixes foreign key column type mismatches where columns
     * were created as mediumInteger but need to be unsignedInteger to match
     * the primary keys they reference (which use increments()).
     * 
     * This fixes MySQL 8.0+ error:
     * SQLSTATE[HY000]: General error: 3780 Referencing column and referenced 
     * column in foreign key constraint are incompatible.
     */
    public function up(): void
    {
        // Fix servers table foreign key columns (renamed from node->node_id, etc. in 2017)
        // service_id renamed to nest_id, option_id renamed to egg_id
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedInteger('node_id')->change();
            $table->unsignedInteger('owner_id')->change();
            $table->unsignedInteger('allocation_id')->change();
            $table->unsignedInteger('nest_id')->change();
            $table->unsignedInteger('egg_id')->change();
        });

        // Fix allocations table foreign key columns (renamed from node->node_id, assigned_to->server_id in 2017)
        Schema::table('allocations', function (Blueprint $table) {
            $table->unsignedInteger('server_id')->nullable()->change();
            $table->unsignedInteger('node_id')->nullable(false)->change();
        });

        // Note: api_permissions table was dropped in migration 2018_01_11_213943, so we skip it

        // Fix server_variables table foreign key columns
        Schema::table('server_variables', function (Blueprint $table) {
            $table->unsignedInteger('server_id')->nullable()->change();
            $table->unsignedInteger('variable_id')->nullable(false)->change();
        });

        // Fix eggs table (formerly service_options) foreign key columns
        // parent_service was renamed to nest_id
        Schema::table('eggs', function (Blueprint $table) {
            $table->unsignedInteger('nest_id')->change();
        });

        // Fix egg_variables table (formerly service_variables) foreign key columns
        // option_id was renamed to egg_id, table was renamed to egg_variables
        Schema::table('egg_variables', function (Blueprint $table) {
            $table->unsignedInteger('egg_id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert servers table columns to mediumInteger
        Schema::table('servers', function (Blueprint $table) {
            $table->mediumInteger('node_id', false, true)->change();
            $table->mediumInteger('owner_id', false, true)->change();
            $table->mediumInteger('allocation_id', false, true)->change();
            $table->mediumInteger('nest_id', false, true)->change();
            $table->mediumInteger('egg_id', false, true)->change();
        });

        // Revert allocations table columns to mediumInteger
        Schema::table('allocations', function (Blueprint $table) {
            $table->mediumInteger('server_id', false, true)->nullable()->change();
            $table->mediumInteger('node_id', false, true)->nullable(false)->change();
        });

        // Revert server_variables table columns to mediumInteger
        Schema::table('server_variables', function (Blueprint $table) {
            $table->mediumInteger('server_id', false, true)->nullable()->change();
            $table->mediumInteger('variable_id', false, true)->nullable(false)->change();
        });

        // Revert eggs table columns to mediumInteger
        Schema::table('eggs', function (Blueprint $table) {
            $table->mediumInteger('nest_id', false, true)->change();
        });

        // Revert egg_variables table columns to mediumInteger
        Schema::table('egg_variables', function (Blueprint $table) {
            $table->mediumInteger('egg_id', false, true)->change();
        });
    }
}
