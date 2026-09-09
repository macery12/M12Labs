<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('extension_trusted_keys', function (Blueprint $table): void {
            // Deliberately nullable and not backfilled: keys admitted before
            // this binding existed must fail closed until a repository refresh
            // verifies them under the currently configured root.
            $table->char('root_fingerprint', 64)->nullable()->after('fingerprint')->index();
        });
    }

    public function down(): void
    {
        Schema::table('extension_trusted_keys', function (Blueprint $table): void {
            $table->dropColumn('root_fingerprint');
        });
    }
};
