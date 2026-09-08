<?php

namespace Everest\Services\Extensions;

/**
 * Which extension packages may load their code into a running panel.
 *
 * Retained as the stable entry point the four load sites and the access
 * middleware already call; the decision itself lives in
 * {@see ExtensionRuntimePlanService}, which also answers the finer question of
 * WHICH surfaces an extension declared.
 *
 * An extension's PHP is only ever require()'d when the package is installed,
 * enabled, in an executable lifecycle state, built for a manifest version this
 * panel accepts, and carrying a capability projection that still matches its
 * manifest — so a disabled or quarantined extension's top-level code never
 * executes. Enablement is enforced at load time, not merely at request time.
 */
class ExtensionRuntimeGate
{
    /**
     * Extension ids permitted to load code, plus package-less core extensions.
     *
     * Returns an empty array when the module is off or the tables are
     * unavailable (a fresh install may run artisan before migrations exist),
     * which keeps the panel bootable and simply loads no extension code.
     *
     * @return string[]
     */
    public static function enabledExtensionIds(): array
    {
        return app(ExtensionRuntimePlanService::class)->enabledIdsIncludingCoreExtensions();
    }

    public static function isEnabled(string $extensionId): bool
    {
        return app(ExtensionRuntimePlanService::class)->isEnabled($extensionId);
    }

    /**
     * Drop the per-process memo. Call after mutating enabled state within a
     * long-lived process (e.g. tests, queue workers) so a subsequent lookup
     * reflects the change.
     */
    public static function flush(): void
    {
        ExtensionRuntimePlanService::flush();
    }
}
