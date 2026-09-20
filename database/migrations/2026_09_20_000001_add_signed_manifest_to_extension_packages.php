<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('extension_packages', function (Blueprint $table): void {
            // Keep the publisher-supplied JSON representation. Decoding into a
            // PHP array loses the signed distinction between {} and [], so the
            // ordinary manifest JSON column cannot always be canonicalized
            // back to the bytes whose digest the publisher signed.
            $table->mediumText('signed_manifest')->nullable()->after('manifest_hash');
        });

        // Best-effort compatibility for already-installed packages. Their JSON
        // column still retains object/list types in the database representation
        // on supported production databases. A package for which it does not
        // will safely fail signature re-verification and require an update.
        DB::table('extension_packages')
            ->whereNull('signed_manifest')
            ->update(['signed_manifest' => DB::raw('manifest')]);
    }

    public function down(): void
    {
        Schema::table('extension_packages', function (Blueprint $table): void {
            $table->dropColumn('signed_manifest');
        });
    }
};
