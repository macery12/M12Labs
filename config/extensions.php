<?php

/*
|--------------------------------------------------------------------------
| Extension Platform
|--------------------------------------------------------------------------
|
| Platform-level settings for the extension system itself. Distinct from
| config/modules/extensions.php (`modules.extensions.*`), which is the
| user-facing module toggle and the legacy core-extension declarations.
|
*/

return [
    /*
     * Installing, updating or uninstalling a package rebuilds the panel's
     * frontend, because extension pages are compiled into the bundle. That
     * build runs on the panel host, so it is bounded here rather than left to
     * whatever the host happens to allow.
     */
    /*
     * A package archive is attacker-controlled input until its checksums and
     * signature verify, and that happens after extraction — so extraction is
     * bounded here rather than trusting the archive's own headers.
     */
    'archive' => [
        'max_entries' => (int) env('EXTENSIONS_ARCHIVE_MAX_ENTRIES', 2000),
        'max_file_bytes' => (int) env('EXTENSIONS_ARCHIVE_MAX_FILE_BYTES', 8 * 1024 * 1024),
        'max_total_bytes' => (int) env('EXTENSIONS_ARCHIVE_MAX_TOTAL_BYTES', 96 * 1024 * 1024),
        // Rejects ZIP bombs: a whole archive that is mostly expansion rather
        // than content. Checked across the archive, since one highly
        // compressible file on its own is unremarkable.
        'max_expansion_ratio' => (int) env('EXTENSIONS_ARCHIVE_MAX_EXPANSION_RATIO', 120),
        'max_path_depth' => (int) env('EXTENSIONS_ARCHIVE_MAX_PATH_DEPTH', 12),
        // Ceiling on a download before any of it is trusted.
        'max_download_bytes' => (int) env('EXTENSIONS_ARCHIVE_MAX_DOWNLOAD_BYTES', 64 * 1024 * 1024),
        'download_timeout_seconds' => (int) env('EXTENSIONS_ARCHIVE_DOWNLOAD_TIMEOUT', 120),
        'download_connect_timeout_seconds' => (int) env('EXTENSIONS_ARCHIVE_CONNECT_TIMEOUT', 10),
        'max_redirects' => (int) env('EXTENSIONS_ARCHIVE_MAX_REDIRECTS', 3),
    ],

    'build' => [
        // Wall-clock ceiling for the frontend build. A build that exceeds this
        // is killed and the previous assets are restored.
        'timeout_seconds' => (int) env('EXTENSIONS_BUILD_TIMEOUT', 900),

        // Passed to the build as NODE_OPTIONS --max-old-space-size. Node's
        // default heap is sized from total system memory, which on a panel host
        // shared with game servers can mean the build is OOM-killed.
        'node_max_old_space_mb' => (int) env('EXTENSIONS_BUILD_NODE_MEMORY_MB', 3072),

        // Refuse a build whose output is implausibly large. Guards against a
        // package that inlines huge assets filling the disk.
        'max_output_bytes' => (int) env('EXTENSIONS_BUILD_MAX_OUTPUT_BYTES', 256 * 1024 * 1024),

        // How long a previous asset set is kept after a successful rebuild.
        // A browser that loaded the old index still requests old chunk names;
        // keeping one previous set avoids a stale-chunk 404 during the window.
        'snapshot_retention_hours' => (int) env('EXTENSIONS_BUILD_SNAPSHOT_RETENTION_HOURS', 24),

        // Verify the host toolchain matches the versions this repo pins in the
        // root package.json before building. A mismatched pnpm resolves a
        // different dependency tree than the lockfile describes.
        'enforce_toolchain' => (bool) env('EXTENSIONS_BUILD_ENFORCE_TOOLCHAIN', true),

        // Reclaim space in the isolated pnpm store after a successful build.
        'prune_store' => (bool) env('EXTENSIONS_BUILD_PRUNE_STORE', true),
    ],
];
