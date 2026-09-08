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
