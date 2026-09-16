<?php

namespace Everest\Services\Extensions\Manifest;

use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\Manifest\Definitions\HookDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PageDefinition;

/**
 * Enforces that declared capabilities and shipped files agree in BOTH
 * directions.
 *
 * A capability declared without its file would fail at runtime in whatever way
 * the missing file happens to fail. A file shipped without its capability is
 * worse: it is executable code the administrator was never shown and never
 * approved. Each rule below is therefore an "if and only if".
 *
 * Every path is derived from the extension id and the declaration — a package
 * never names a file itself, so it cannot point a surface at something outside
 * its own directory.
 */
class ExtensionCapabilityFileValidator
{
    public function assertMatchesFiles(ExtensionManifest $manifest): void
    {
        $paths = array_flip($manifest->filePaths());
        $id = $manifest->id;
        $capabilities = $manifest->capabilities;

        $backend = sprintf('app/Extensions/Packages/%s/', $id);
        $frontend = sprintf('frontend/src/extensions/packages/%s/', $id);

        $this->assertPair(
            $capabilities->clientRoutes,
            isset($paths[$backend . 'routes/client.php']),
            'capabilities.routes.client',
            $backend . 'routes/client.php'
        );

        $this->assertPair(
            $capabilities->adminRoutes,
            isset($paths[$backend . 'routes/admin.php']),
            'capabilities.routes.admin',
            $backend . 'routes/admin.php'
        );

        $this->assertPair(
            $capabilities->schedule,
            isset($paths[$backend . 'schedule.php']),
            'capabilities.schedule',
            $backend . 'schedule.php'
        );

        $this->assertPair(
            $capabilities->migrations,
            $this->hasAnyUnder($paths, $backend . 'database/migrations/'),
            'capabilities.database.migrations',
            $backend . 'database/migrations/'
        );

        $this->assertPair(
            $capabilities->commands !== [],
            $this->hasAnyUnder($paths, $backend . 'Console/Commands/'),
            'capabilities.commands',
            $backend . 'Console/Commands/'
        );

        $this->assertPair(
            $capabilities->queues !== [],
            $this->hasAnyUnder($paths, $backend . 'Jobs/'),
            'capabilities.queues',
            $backend . 'Jobs/'
        );

        foreach ($capabilities->hooks as $hook) {
            /** @var HookDefinition $hook */
            $expected = sprintf('%sHooks/%s.php', $backend, $hook->handler);

            if (!isset($paths[$expected])) {
                throw new DisplayException(sprintf('The manifest declares the hook handler "%s" for "%s" but the package does not ship %s.', $hook->handler, $hook->event, $expected));
            }
        }

        // A hook class shipped without a declaration is inert (the dispatcher
        // resolves only declared handlers) but still signals a manifest that
        // does not describe the package, so it is rejected.
        $declaredHooks = array_map(
            fn (HookDefinition $hook): string => sprintf('%sHooks/%s.php', $backend, $hook->handler),
            $capabilities->hooks
        );
        foreach (array_keys($paths) as $path) {
            if (str_starts_with((string) $path, $backend . 'Hooks/') && !in_array($path, $declaredHooks, true)) {
                throw new DisplayException(sprintf('The package ships %s but does not declare it under capabilities.hooks.', $path));
            }
        }

        // One-directional, unlike the rules above. A declared binding must ship
        // its class, or the container would be told to share something that does
        // not exist. The reverse is not a fault: a package ships plenty of
        // classes it has no reason to make shared, and requiring a declaration
        // for each would turn an optimisation into paperwork.
        foreach ($capabilities->bindings as $binding) {
            $expected = sprintf('%s%s.php', $backend, $binding);

            if (!isset($paths[$expected])) {
                throw new DisplayException(sprintf('The manifest declares the binding "%s" but the package does not ship %s.', $binding, $expected));
            }
        }

        foreach (['server' => $capabilities->serverPages, 'admin' => $capabilities->adminPages] as $surface => $pages) {
            foreach ($pages as $page) {
                /** @var PageDefinition $page */
                $expected = sprintf('%spages/%s/%s.tsx', $frontend, $surface, $page->slug);

                if (!isset($paths[$expected])) {
                    throw new DisplayException(sprintf('The manifest declares the %s page "%s" but the package does not ship %s.', $surface, $page->slug, $expected));
                }
            }
        }

        $this->assertNoUndeclaredPages($paths, $frontend, 'server', $capabilities->serverPages);
        $this->assertNoUndeclaredPages($paths, $frontend, 'admin', $capabilities->adminPages);
        $this->assertNoLegacyLayout($paths, $frontend, $id);
    }

    /**
     * @param array<string, int> $paths
     * @param array<int, PageDefinition> $pages
     */
    private function assertNoUndeclaredPages(array $paths, string $frontend, string $surface, array $pages): void
    {
        $prefix = sprintf('%spages/%s/', $frontend, $surface);
        $declared = array_map(fn (PageDefinition $page): string => $prefix . $page->slug . '.tsx', $pages);

        foreach (array_keys($paths) as $path) {
            $path = (string) $path;

            if (!str_starts_with($path, $prefix) || !str_ends_with($path, '.tsx')) {
                continue;
            }

            // Only the page entry itself must be declared; a package is free to
            // ship supporting components beside it in a subdirectory.
            if (substr_count(substr($path, strlen($prefix)), '/') > 0) {
                continue;
            }

            if (!in_array($path, $declared, true)) {
                throw new DisplayException(sprintf('The package ships the %s page %s but does not declare it under capabilities.pages.%s.', $surface, $path, $surface));
            }
        }
    }

    /**
     * The v2 layout inferred surfaces from meta.json and fixed entry filenames.
     * Accepting it alongside v3 would reintroduce exactly the inference this
     * contract removes, so its marker files are rejected outright.
     *
     * @param array<string, int> $paths
     */
    private function assertNoLegacyLayout(array $paths, string $frontend, string $id): void
    {
        foreach (['meta.json', 'index.tsx', 'admin.tsx'] as $legacy) {
            if (isset($paths[$frontend . $legacy])) {
                throw new DisplayException(sprintf('The package ships %s%s, which belongs to the retired v2 layout. Declare pages under capabilities.pages and ship them as pages/<surface>/<slug>.tsx.', $frontend, $legacy));
            }
        }
    }

    /**
     * @param array<string, int> $paths
     */
    private function hasAnyUnder(array $paths, string $prefix): bool
    {
        foreach (array_keys($paths) as $path) {
            if (str_starts_with((string) $path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function assertPair(bool $declared, bool $shipped, string $capability, string $path): void
    {
        if ($declared && !$shipped) {
            throw new DisplayException(sprintf('The manifest declares "%s" but the package does not ship %s.', $capability, $path));
        }

        if (!$declared && $shipped) {
            throw new DisplayException(sprintf('The package ships %s but does not declare "%s" in its manifest.', $path, $capability));
        }
    }
}
