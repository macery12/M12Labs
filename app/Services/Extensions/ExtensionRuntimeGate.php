<?php

namespace Everest\Services\Extensions;

use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;

/**
 * Single source of truth for which extension packages are allowed to load
 * their code into a running panel.
 *
 * An extension's PHP (admin routes, client routes, artisan commands, schedule
 * entries) is only ever require()'d/registered when the extension is both
 * installed on disk AND enabled in the database. Every load site consults
 * {@see enabledExtensionIds()} and skips anything absent from it, so a disabled
 * extension's top-level code never executes — the enabled flag is enforced at
 * load time, not merely at request time. There is deliberately no cached
 * manifest: the two authorities (the filesystem glob that finds the files and
 * the ExtensionConfig table queried here) are always live, so nothing can go
 * stale and re-enable removed or disabled code.
 */
class ExtensionRuntimeGate
{
    /**
     * Lifecycle states in which a package's code may be loaded. Any other state
     * ("unsupported", "failed", "installing", ...) is inert regardless of the
     * enabled flag. Packages with no extension_packages row at all — core
     * extensions declared in config/modules/extensions.php — are unaffected,
     * because the check is an exclusion list rather than a join.
     */
    private const EXECUTABLE_STATES = ['enabled', 'installed_disabled'];

    /**
     * Per-process memo. The enabled set does not change within a single
     * request/command, so the four load sites share one query.
     *
     * @var string[]|null
     */
    private static ?array $enabledIds = null;

    /**
     * Extension ids that are permitted to load code: the module is on and the
     * extension's ExtensionConfig is enabled. Returns an empty array when the
     * module is disabled or the config table is unavailable (fresh installs may
     * run artisan before migrations exist), which keeps the panel bootable and
     * simply loads no extension code.
     *
     * @return string[]
     */
    public static function enabledExtensionIds(): array
    {
        if (self::$enabledIds !== null) {
            return self::$enabledIds;
        }

        if (!config('modules.extensions.enabled')) {
            return self::$enabledIds = [];
        }

        try {
            // A package quarantined by the lifecycle state (built for a manifest
            // version this panel no longer accepts, or left failed by a rolled
            // back operation) never loads, even if its enabled flag says true.
            // The flag alone is not sufficient: an administrator can toggle it,
            // and an aborted install can leave one set.
            $blocked = ExtensionPackage::query()
                ->whereNotIn('state', self::EXECUTABLE_STATES)
                ->pluck('extension_id')
                ->all();

            return self::$enabledIds = ExtensionConfig::query()
                ->where('enabled', true)
                ->when($blocked !== [], fn ($query) => $query->whereNotIn('extension_id', $blocked))
                ->pluck('extension_id')
                ->all();
        } catch (\Throwable) {
            // Do not memoize the failure: a later call (after migrations run in
            // the same process) should be able to resolve the real set.
            return [];
        }
    }

    /**
     * Whether a specific extension is permitted to load code.
     */
    public static function isEnabled(string $extensionId): bool
    {
        return in_array($extensionId, self::enabledExtensionIds(), true);
    }

    /**
     * Drop the per-process memo. Call after mutating enabled state within a
     * long-lived process (e.g. tests, queue workers) so a subsequent lookup
     * reflects the change.
     */
    public static function flush(): void
    {
        self::$enabledIds = null;
    }
}
