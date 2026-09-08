<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/*
 * Introduces the persisted lifecycle state on extension packages and quarantines
 * every package built for a manifest version this panel no longer accepts.
 *
 * Manifest v3 is the only schema Alpha 4.0 understands. A v1/v2 package left
 * enabled would keep loading its routes and schedule entries while its manifest
 * can no longer be parsed, so the state is recorded here ("unsupported") and the
 * package is disabled through the same ExtensionConfig flag the runtime gate
 * already reads. Recording the state separately from the enabled flag is what
 * lets the admin UI distinguish "an administrator turned this off" from "this
 * package cannot run on this panel", and offer Uninstall instead of Enable.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('extension_packages', function (Blueprint $table): void {
            // Denormalized from the stored manifest so the runtime plan never
            // has to decode a full manifest on a hot path.
            $table->unsignedTinyInteger('manifest_version')->default(1)->after('manifest');
            $table->string('state', 24)->default('installed_disabled')->after('manifest_version');
            $table->text('state_reason')->nullable()->after('state');

            $table->index('state');
        });

        // Backfill in PHP rather than with JSON SQL functions: the panel runs on
        // both MySQL and (in tests) SQLite, whose JSON support differs.
        DB::table('extension_packages')
            ->select(['id', 'manifest'])
            ->orderBy('id')
            ->chunk(100, function ($packages): void {
                foreach ($packages as $package) {
                    $manifest = json_decode((string) $package->manifest, true);
                    $version = is_array($manifest) ? (int) ($manifest['manifestVersion'] ?? 1) : 1;

                    DB::table('extension_packages')
                        ->where('id', $package->id)
                        ->update([
                            'manifest_version' => max(1, $version),
                            'state' => $version >= 3 ? 'installed_disabled' : 'unsupported',
                            'state_reason' => $version >= 3
                                ? null
                                : sprintf('Built for manifest version %d. This panel requires manifest version 3.', max(1, $version)),
                        ]);
                }
            });

        // Packages that were enabled under the old contract stop loading code.
        $unsupported = DB::table('extension_packages')
            ->where('state', 'unsupported')
            ->pluck('extension_id')
            ->all();

        if ($unsupported !== []) {
            DB::table('extension_configs')
                ->whereIn('extension_id', $unsupported)
                ->update(['enabled' => false]);
        }

        // A package that was already enabled and IS v3-capable keeps running.
        DB::table('extension_packages')
            ->where('state', 'installed_disabled')
            ->whereIn('extension_id', DB::table('extension_configs')->where('enabled', true)->select('extension_id'))
            ->update(['state' => 'enabled']);
    }

    public function down(): void
    {
        Schema::table('extension_packages', function (Blueprint $table): void {
            $table->dropIndex(['state']);
            $table->dropColumn(['manifest_version', 'state', 'state_reason']);
        });
    }
};
