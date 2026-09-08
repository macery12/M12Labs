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

    /*
     * Package signing.
     *
     * Who provisions what:
     *
     *  - The ROOT keypair is generated once, by whoever operates the extension
     *    repository, and its private half is kept offline. It never signs a
     *    package; it signs release-key records, which is all a panel needs to
     *    decide whom to trust. In this deployment the repository is
     *    M12Labs-Extensions, and `tools/m12labs_extension_tool.py authorize-key`
     *    is the only operation that touches the root key.
     *  - A RELEASE key lives in CI and signs artifacts. It is short-lived and
     *    replaceable: authorizing a new one or revoking an old one is a change
     *    to registry.json, not to any panel.
     *  - An OPERATOR pins the root's public key and fingerprint below, out of
     *    band. That pin is the entire trust decision this panel makes.
     *
     * With no root pinned the panel cannot attribute a package to anybody, so
     * it admits packages as UNVERIFIED rather than refusing everything — but an
     * unverified package may not declare hooks, queues or dangerous
     * permissions. Pinning a root is what turns enforcement on.
     */
    'signing' => [
        // The OFFLINE root key, base64-encoded raw Ed25519 public key. It never
        // touches a build machine: it signs short-lived release keys, and those
        // sign artifacts. Only this value is pinned by the panel.
        'root_public_key' => env('EXTENSIONS_SIGNING_ROOT_KEY', 'ggFN5FMVZ0I3WAWstAAK9Gh7yTN4DMA/aKVAcyxamRw='),

        // sha256 of the decoded root key. An operator can compare it out of
        // band, and a swapped root_public_key fails the comparison rather than
        // silently becoming a new root of trust.
        'root_fingerprint' => env(
            'EXTENSIONS_SIGNING_ROOT_FINGERPRINT',
            'd00b21be261647e0518f232b782f273d2a9f4485523862040321a122c4f0c1c3'
        ),

        // Refuse to install an artifact that is not signed by a trusted release
        // key. Turning this off is not supported for repository installs.
        'require_signature' => (bool) env('EXTENSIONS_REQUIRE_SIGNATURE', true),

        // Whether a local .M12LabsExtension archive may be installed unsigned,
        // with an explicit acknowledgement. Such a package can never declare
        // hooks, queues or a dangerous permission — the panel cannot attribute
        // it to anybody, so it must not run code on core's behalf.
        'allow_unsigned_local' => (bool) env('EXTENSIONS_ALLOW_UNSIGNED_LOCAL', false),
    ],

    'queues' => [
        // How long an update or uninstall waits for an extension's in-flight
        // jobs before giving up. Queued work is discarded immediately; this
        // budget covers jobs already executing, which cannot be interrupted.
        // On timeout the operation refuses rather than deleting class files out
        // from under a running worker.
        'drain_timeout_seconds' => (int) env('EXTENSIONS_DRAIN_TIMEOUT', 60),
    ],
];
