<?php

namespace Everest\Services\Extensions;

use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Everest\Services\Extensions\Manifest\ExtensionManifest;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\Definitions\HookDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PageDefinition;
use Everest\Services\Extensions\Manifest\Definitions\QueueDefinition;
use Everest\Services\Extensions\Manifest\Definitions\SecretDefinition;
use Everest\Services\Extensions\Manifest\Definitions\SettingDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PermissionDefinition;

/**
 * One entry in the runtime plan: an extension that is allowed to load code, and
 * exactly what it declared it may do.
 */
final readonly class ExtensionRuntimeEntry
{
    public function __construct(
        public string $id,
        public string $version,
        public ExtensionCapabilitySet $capabilities,
    ) {
    }
}

/**
 * The single authority on which extensions may load code, and which surfaces.
 *
 * The enabled flag alone is not sufficient. A package also has to be in an
 * executable lifecycle state, built for a manifest version this panel accepts,
 * carrying a capability projection that still matches its manifest, and
 * satisfying the signature policy. Any of those failing makes the package inert
 * rather than partially live.
 *
 * Capabilities — not the filesystem — decide what loads. The load sites ask for
 * the extensions holding a capability rather than globbing for files, which
 * closes the gap where a package shipped a route file it never declared.
 */
class ExtensionRuntimePlanService
{
    /** Lifecycle states in which a package's code may be loaded. */
    public const EXECUTABLE_STATES = ['enabled'];

    /**
     * Per-process memo. The plan does not change within one request or command,
     * so every load site shares a single query.
     *
     * @var array<string, ExtensionRuntimeEntry>|null
     */
    private static ?array $plan = null;

    /**
     * Enabled extension ids with no installed package row — the legacy
     * core-extension declarations in config/modules/extensions.php. They ship
     * no package and therefore no capabilities, but they remain part of the
     * request-time enabled set so their access middleware behaves as before.
     *
     * @var array<int, string>|null
     */
    private static ?array $coreExtensionIds = null;

    /**
     * @return array<string, ExtensionRuntimeEntry> keyed by extension id
     */
    public function plan(): array
    {
        if (self::$plan !== null) {
            return self::$plan;
        }

        if (!config('modules.extensions.enabled')) {
            self::$coreExtensionIds = [];

            return self::$plan = [];
        }

        try {
            $enabledIds = ExtensionConfig::query()
                ->where('enabled', true)
                ->pluck('extension_id')
                ->all();

            if ($enabledIds === []) {
                self::$coreExtensionIds = [];

                return self::$plan = [];
            }

            $packages = ExtensionPackage::query()
                ->whereIn('extension_id', $enabledIds)
                ->get(['extension_id', 'installed_version', 'state', 'manifest_version', 'capabilities', 'capability_hash', 'signature_state']);

            self::$coreExtensionIds = array_values(array_diff($enabledIds, $packages->pluck('extension_id')->all()));

            $plan = [];
            foreach ($packages as $package) {
                $entry = $this->entryFor($package);
                if ($entry !== null) {
                    $plan[$entry->id] = $entry;
                }
            }

            return self::$plan = $plan;
        } catch (\Throwable) {
            // Do not memoize the failure: a fresh install may run artisan before
            // the tables exist, and a later call in the same process (after
            // migrations) should resolve the real plan.
            return [];
        }
    }

    /**
     * Extensions holding a capability, as `capability => value` where value is
     * the declaration (a bool for routes/schedule, definitions for the rest).
     *
     * @return array<string, ExtensionRuntimeEntry>
     */
    public function withCapability(string $capability): array
    {
        return array_filter($this->plan(), fn (ExtensionRuntimeEntry $entry): bool => match ($capability) {
            'routes.client' => $entry->capabilities->clientRoutes,
            'routes.admin' => $entry->capabilities->adminRoutes,
            'schedule' => $entry->capabilities->schedule,
            'commands' => $entry->capabilities->commands !== [],
            'hooks' => $entry->capabilities->hooks !== [],
            'queues' => $entry->capabilities->queues !== [],
            'secrets' => $entry->capabilities->secrets !== [],
            'pages.server' => $entry->capabilities->hasServerPages(),
            'pages.admin' => $entry->capabilities->hasAdminPages(),
            default => false,
        });
    }

    /**
     * Ids that may load package code. Excludes core extensions, which ship none.
     *
     * @return array<int, string>
     */
    public function enabledIds(): array
    {
        return array_keys($this->plan());
    }

    /**
     * The request-time enabled set: installed packages that may load, plus
     * package-less core extensions. This is what the access middleware gates
     * on, so it stays wider than the set that loads code.
     *
     * @return array<int, string>
     */
    public function enabledIdsIncludingCoreExtensions(): array
    {
        $this->plan();

        return array_values(array_merge(array_keys(self::$plan ?? []), self::$coreExtensionIds ?? []));
    }

    public function isEnabled(string $extensionId): bool
    {
        return in_array($extensionId, $this->enabledIdsIncludingCoreExtensions(), true);
    }

    public function entry(string $extensionId): ?ExtensionRuntimeEntry
    {
        return $this->plan()[$extensionId] ?? null;
    }

    /**
     * Every hook handler subscribed to an event, across all enabled extensions.
     *
     * @return array<int, array{id: string, version: string, hook: HookDefinition}>
     */
    public function hooksFor(string $event): array
    {
        $handlers = [];

        foreach ($this->plan() as $entry) {
            foreach ($entry->capabilities->hooksFor($event) as $hook) {
                $handlers[] = ['id' => $entry->id, 'version' => $entry->version, 'hook' => $hook];
            }
        }

        return $handlers;
    }

    public static function flush(): void
    {
        self::$plan = null;
        self::$coreExtensionIds = null;
    }

    /**
     * Decide whether one installed package may load, and rehydrate its
     * capabilities from the stored projection.
     */
    private function entryFor(ExtensionPackage $package): ?ExtensionRuntimeEntry
    {
        if (!in_array($package->state, self::EXECUTABLE_STATES, true)) {
            return null;
        }

        if ((int) $package->manifest_version !== ExtensionManifest::VERSION) {
            return null;
        }

        if (!$this->signatureStateAllowed((string) $package->signature_state)) {
            return null;
        }

        $capabilities = $this->hydrate($package->capabilities);
        if ($capabilities === null) {
            return null;
        }

        // The projection is denormalized from the manifest so this query stays
        // cheap. The hash is what keeps the duplication honest: a projection
        // edited in the database no longer matches, and the package goes inert
        // rather than running with capabilities nobody approved.
        if ($package->capability_hash !== null && $capabilities->hash() !== $package->capability_hash) {
            return null;
        }

        return new ExtensionRuntimeEntry($package->extension_id, (string) $package->installed_version, $capabilities);
    }

    /**
     * Signature policy.
     *
     * Publisher signing is not implemented yet, so "unsigned" is currently
     * acceptable and the panel's trust rests on the registry checksum plus
     * review. Turning on extensions.signing.require_signature tightens this to
     * packages that verified against the pinned key, plus ones an operator
     * explicitly acknowledged as unsigned.
     */
    private function signatureStateAllowed(string $state): bool
    {
        if ($state === 'revoked') {
            return false;
        }

        if (!config('extensions.signing.require_signature', false)) {
            return true;
        }

        return in_array($state, ['verified', 'unsigned_acknowledged'], true);
    }

    private function hydrate($capabilities): ?ExtensionCapabilitySet
    {
        if (!is_array($capabilities)) {
            return null;
        }

        $routes = (array) ($capabilities['routes'] ?? []);
        $pages = (array) ($capabilities['pages'] ?? []);
        $database = (array) ($capabilities['database'] ?? []);
        $permissions = (array) ($capabilities['permissions'] ?? []);
        $settings = (array) ($capabilities['settings'] ?? []);

        return new ExtensionCapabilitySet(
            clientRoutes: (bool) ($routes['client'] ?? false),
            adminRoutes: (bool) ($routes['admin'] ?? false),
            serverPages: $this->hydratePages((array) ($pages['server'] ?? [])),
            adminPages: $this->hydratePages((array) ($pages['admin'] ?? [])),
            adminPermissions: array_map(
                fn (array $p): PermissionDefinition => new PermissionDefinition(
                    (string) $p['key'],
                    (string) $p['labelKey'],
                    isset($p['descriptionKey']) ? (string) $p['descriptionKey'] : null,
                    (bool) ($p['dangerous'] ?? false),
                ),
                (array) ($permissions['admin'] ?? [])
            ),
            migrations: (bool) ($database['migrations'] ?? false),
            tables: array_values(array_map('strval', (array) ($database['tables'] ?? []))),
            hooks: array_map(
                fn (array $h): HookDefinition => new HookDefinition(
                    (string) $h['event'],
                    (string) $h['handler'],
                    (string) $h['mode'],
                    (int) ($h['timeoutMs'] ?? 3000),
                ),
                (array) ($capabilities['hooks'] ?? [])
            ),
            queues: array_map(
                fn (array $q): QueueDefinition => new QueueDefinition(
                    name: (string) $q['name'],
                    maxAttempts: (int) ($q['maxAttempts'] ?? 3),
                    timeoutSeconds: (int) ($q['timeoutSeconds'] ?? 60),
                    backoffSeconds: array_map('intval', (array) ($q['backoffSeconds'] ?? [10, 60, 300])),
                    rateLimit: isset($q['rateLimit']) ? (string) $q['rateLimit'] : null,
                    maxConcurrent: isset($q['maxConcurrent']) ? (int) $q['maxConcurrent'] : null,
                    maxOutstanding: isset($q['maxOutstanding']) ? (int) $q['maxOutstanding'] : null,
                    uniqueForSeconds: isset($q['uniqueForSeconds']) ? (int) $q['uniqueForSeconds'] : null,
                ),
                (array) ($capabilities['queues'] ?? [])
            ),
            schedule: (bool) ($capabilities['schedule'] ?? false),
            commands: array_values(array_map('strval', (array) ($capabilities['commands'] ?? []))),
            secrets: array_map(
                fn (array $s): SecretDefinition => new SecretDefinition(
                    (string) $s['key'],
                    (string) $s['labelKey'],
                    isset($s['helpKey']) ? (string) $s['helpKey'] : null,
                    (bool) ($s['rotatable'] ?? true),
                ),
                (array) ($capabilities['secrets'] ?? [])
            ),
            settings: array_map(
                fn (array $f): SettingDefinition => new SettingDefinition(
                    key: (string) $f['key'],
                    type: (string) $f['type'],
                    labelKey: (string) $f['labelKey'],
                    helpKey: isset($f['helpKey']) ? (string) $f['helpKey'] : null,
                    required: (bool) ($f['required'] ?? false),
                    default: $f['default'] ?? null,
                    minLength: isset($f['minLength']) ? (int) $f['minLength'] : null,
                    maxLength: isset($f['maxLength']) ? (int) $f['maxLength'] : null,
                    pattern: isset($f['pattern']) ? (string) $f['pattern'] : null,
                    enum: isset($f['enum']) ? array_map('strval', (array) $f['enum']) : null,
                    min: isset($f['min']) ? $f['min'] + 0 : null,
                    max: isset($f['max']) ? $f['max'] + 0 : null,
                    urlHosts: isset($f['urlHosts']) ? array_map('strval', (array) $f['urlHosts']) : null,
                    visibility: (string) ($f['visibility'] ?? 'admin'),
                    requiresRebuild: (bool) ($f['requiresRebuild'] ?? false),
                ),
                (array) ($settings['fields'] ?? [])
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     *
     * @return array<int, PageDefinition>
     */
    private function hydratePages(array $pages): array
    {
        return array_map(
            fn (array $page): PageDefinition => new PageDefinition(
                slug: (string) $page['slug'],
                labelKey: (string) $page['labelKey'],
                icon: (string) ($page['icon'] ?? 'puzzle'),
                category: (string) ($page['category'] ?? 'general'),
                order: (int) ($page['order'] ?? 100),
                requiredServerPermission: isset($page['requiredServerPermission']) ? (string) $page['requiredServerPermission'] : null,
                requiredExtensionPermission: isset($page['requiredExtensionPermission']) ? (string) $page['requiredExtensionPermission'] : null,
            ),
            $pages
        );
    }
}
