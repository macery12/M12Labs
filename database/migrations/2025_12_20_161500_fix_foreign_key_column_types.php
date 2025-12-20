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
        // Fix servers table foreign key columns
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedInteger('node')->change();
            $table->unsignedInteger('owner')->change();
            $table->unsignedInteger('allocation')->change();
            $table->unsignedInteger('service')->change();
            $table->unsignedInteger('option')->change();
        });

        // Fix allocations table foreign key columns
        Schema::table('allocations', function (Blueprint $table) {
            $table->unsignedInteger('assigned_to')->nullable()->change();
            $table->unsignedInteger('node')->nullable(false)->change();
        });

        // Fix api_permissions table foreign key columns
        Schema::table('api_permissions', function (Blueprint $table) {
            $table->unsignedInteger('key_id')->nullable(false)->change();
        });

        // Fix nodes table foreign key columns
        Schema::table('nodes', function (Blueprint $table) {
            $table->unsignedInteger('location')->nullable(false)->change();
        });

        // Fix server_variables table foreign key columns
        Schema::table('server_variables', function (Blueprint $table) {
            $table->unsignedInteger('server_id')->nullable()->change();
            $table->unsignedInteger('variable_id')->nullable(false)->change();
        });

        // Fix service_options table foreign key columns
        Schema::table('service_options', function (Blueprint $table) {
            $table->unsignedInteger('parent_service')->change();
        });

        // Fix service_variables table foreign key columns
        Schema::table('service_variables', function (Blueprint $table) {
            $table->unsignedInteger('option_id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert servers table columns to mediumInteger
        Schema::table('servers', function (Blueprint $table) {
            $table->mediumInteger('node', false, true)->change();
            $table->mediumInteger('owner', false, true)->change();
            $table->mediumInteger('allocation', false, true)->change();
            $table->mediumInteger('service', false, true)->change();
            $table->mediumInteger('option', false, true)->change();
        });

        // Revert allocations table columns to mediumInteger
        Schema::table('allocations', function (Blueprint $table) {
            $table->mediumInteger('assigned_to', false, true)->nullable()->change();
            $table->mediumInteger('node', false, true)->nullable(false)->change();
        });

        // Revert api_permissions table columns to mediumInteger
        Schema::table('api_permissions', function (Blueprint $table) {
            $table->mediumInteger('key_id', false, true)->nullable(false)->change();
        });

        // Revert nodes table columns to mediumInteger
        Schema::table('nodes', function (Blueprint $table) {
            $table->mediumInteger('location', false, true)->nullable(false)->change();
        });

        // Revert server_variables table columns to mediumInteger
        Schema::table('server_variables', function (Blueprint $table) {
            $table->mediumInteger('server_id', false, true)->nullable()->change();
            $table->mediumInteger('variable_id', false, true)->nullable(false)->change();
        });

        // Revert service_options table columns to mediumInteger
        Schema::table('service_options', function (Blueprint $table) {
            $table->mediumInteger('parent_service', false, true)->change();
        });

        // Revert service_variables table columns to mediumInteger
        Schema::table('service_variables', function (Blueprint $table) {
            $table->mediumInteger('option_id', false, true)->change();
        });
    }
}
