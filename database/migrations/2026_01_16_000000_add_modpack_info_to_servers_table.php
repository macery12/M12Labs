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
        Schema::table('servers', function (Blueprint $table) {
            $table->integer('installed_modpack_id')->nullable()->after('mods_enabled');
            $table->string('installed_modpack_name')->nullable()->after('installed_modpack_id');
            $table->string('installed_modpack_version')->nullable()->after('installed_modpack_name');
            $table->integer('installed_modpack_file_id')->nullable()->after('installed_modpack_version');
            $table->json('installed_modpack_files')->nullable()->after('installed_modpack_file_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn([
                'installed_modpack_id',
                'installed_modpack_name',
                'installed_modpack_version',
                'installed_modpack_file_id',
                'installed_modpack_files',
            ]);
        });
    }
};
