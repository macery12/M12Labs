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
use Everest\Services\Extensions\Manifest\ExtensionCapabilityVocabulary;
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
     * @return array<string, ExtensionRuntimeEntry> keyed by extension id
     */
    public function plan(): array
    {
        return $this->resolve()['plan'];
    }

    /**
     * Build a live snapshot of the runtime state.
     *
     * This deliberately is not cached in a static or singleton property.
     * Octane workers, queue workers and schedule:work all serve more than one
     * operation in the same process; a process-local snapshot would let an
     * extension continue executing after another process disabled it or marked
     * its signing key revoked.
     *
     * @return array{plan: array<string, ExtensionRuntimeEntry>, core: array<int, string>}
     */
    private function resolve(): array
    {
        if (!config('modules.extensions.enabled')) {
            return ['plan' => [], 'core' => []];
        }

        try {
            // Keep enablement and package state in one database snapshot. Two
            // separate reads leave a window where another worker can disable
            // the extension after the enabled-id query but before this process
            // loads and executes its package capabilities.
            $packages = ExtensionPackage::query()
                ->join('extension_configs', 'extension_configs.extension_id', '=', 'extension_packages.extension_id')
                ->where('extension_configs.enabled', true)
                ->get([
                    'extension_packages.extension_id',
                    'extension_packages.installed_version',
                    'extension_packages.state',
                    'extension_packages.manifest_version',
                    'extension_packages.capabilities',
                    'extension_packages.capability_hash',
                    'extension_packages.signature_state',
                    'extension_packages.signature_key_id',
                ]);

            $usableKeyIds = $this->usableKeyIdsFor($packages->pluck('signature_key_id')->all());

            // A missing package row does not by itself make an id a trusted
            // core extension. Only ids explicitly declared in the local core
            // configuration receive that legacy treatment; otherwise a stale
            // or manually inserted config row could bypass package/signature
            // checks through the core-extension fallback.
            $configuredCoreIds = array_keys((array) config('modules.extensions.available', []));
            $coreExtensionIds = $configuredCoreIds === []
                ? []
                : ExtensionConfig::query()
                    ->where('enabled', true)
                    ->whereIn('extension_id', $configuredCoreIds)
                    ->whereNotIn('extension_id', ExtensionPackage::query()->select('extension_id'))
                    ->pluck('extension_id')
                    ->all();

            $plan = [];
            foreach ($packages as $package) {
                $entry = $this->entryFor($package, $usableKeyIds);
                if ($entry !== null) {
                    $plan[$entry->id] = $entry;
                }
            }

            return ['plan' => $plan, 'core' => $coreExtensionIds];
        } catch (\Throwable) {
            // A fresh install may run artisan before the tables exist. Failing
            // closed keeps the panel bootable and a later call re-checks the
            // database rather than retaining the failure.
            return ['plan' => [], 'core' => []];
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
            default => str_starts_with($capability, 'privileged.')
                && $entry->capabilities->grantsPrivilege(substr($capability, 11)),
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
        $resolved = $this->resolve();

        return array_values(array_merge(array_keys($resolved['plan']), $resolved['core']));
    }

    public function isEnabled(string $extensionId): bool
    {
        return in_array($extensionId, $this->enabledIdsIncludingCoreExtensions(), true);
    }

    public function entry(string $extensionId): ?ExtensionRuntimeEntry
    {
        if (!config('modules.extensions.enabled')) {
            return null;
        }

        try {
            $package = ExtensionPackage::query()
                ->join('extension_configs', 'extension_configs.extension_id', '=', 'extension_packages.extension_id')
                ->where('extension_packages.extension_id', $extensionId)
                ->where('extension_configs.enabled', true)
                ->first([
                    'extension_packages.extension_id',
                    'extension_packages.installed_version',
                    'extension_packages.state',
                    'extension_packages.manifest_version',
                    'extension_packages.capabilities',
                    'extension_packages.capability_hash',
                    'extension_packages.signature_state',
                    'extension_packages.signature_key_id',
                ]);

            return $package === null ? null : $this->entryFor($package);
        } catch (\Throwable) {
            return null;
        }
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
        // Retained as a compatibility no-op for lifecycle callers and older
        // extensions. Runtime state is resolved live, so there is no
        // process-local plan left to invalidate.
    }

    /**
     * Why an installed package is not in the runtime plan.
     *
     * entryFor() answers yes/no, which is all the request path needs; an
     * operator staring at a disabled extension needs the reason. Mirrors the
     * checks below in the same order, and returns null when the package does
     * load. The keys are stable identifiers for the UI to translate, not prose.
     */
    public function exclusionReason(ExtensionPackage $package): ?string
    {
        if (!config('modules.extensions.enabled', true)) {
            return 'module_disabled';
        }

        $enabled = ExtensionConfig::query()
            ->where('extension_id', $package->extension_id)
            ->value('enabled');

        if (!$enabled) {
            return 'config_disabled';
        }

        if (!in_array($package->state, self::EXECUTABLE_STATES, true)) {
            return 'state';
        }

        if ((int) $package->manifest_version !== ExtensionManifest::VERSION) {
            return 'manifest_version';
        }

        if (!$this->signatureStateAllowed((string) $package->signature_state, $package->signature_key_id)) {
            return 'signature';
        }

        $capabilities = $this->hydrate($package->capabilities);
        if ($capabilities === null) {
            return 'capabilities_missing';
        }

        if (!$this->unverifiedCapabilitiesAllowed((string) $package->signature_state, $capabilities)) {
            return 'signature';
        }

        if ($package->capability_hash !== null && $capabilities->hash() !== $package->capability_hash) {
            return 'capability_hash';
        }

        return null;
    }

    /**
     * Decide whether one installed package may load, and rehydrate its
     * capabilities from the stored projection.
     */
    private function entryFor(ExtensionPackage $package, ?array $usableKeyIds = null): ?ExtensionRuntimeEntry
    {
        if (!in_array($package->state, self::EXECUTABLE_STATES, true)) {
            return null;
        }

        if ((int) $package->manifest_version !== ExtensionManifest::VERSION) {
            return null;
        }

        if (!$this->signatureStateAllowed((string) $package->signature_state, $package->signature_key_id, $usableKeyIds)) {
            return null;
        }

        $capabilities = $this->hydrate($package->capabilities);
        if ($capabilities === null) {
            return null;
        }

        // Defense in depth for packages installed before the unsigned policy
        // was tightened. A stale unsigned_acknowledged row cannot keep loading
        // routes, pages, hooks, jobs or any other executable contribution.
        if (!$this->unverifiedCapabilitiesAllowed((string) $package->signature_state, $capabilities)) {
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
     * When enforcement is enabled, only a verified package or an explicitly
     * acknowledged unsigned package may enter the plan. The latter is checked
     * separately below and is allowed only when it has no executable or
     * privileged capabilities. Disabling enforcement is an explicit local
     * development policy and retains the historical permissive behavior.
     */
    private function signatureStateAllowed(string $state, ?string $keyId, ?array $usableKeyIds = null): bool
    {
        if ($state === 'revoked') {
            return false;
        }

        $signature = app(ExtensionSignatureService::class);
        if (!$signature->signingRequired()) {
            return true;
        }

        if (!$signature->rootPinned()) {
            return false;
        }

        if ($state === 'verified') {
            return $usableKeyIds === null
                ? $signature->isTrustedKeyUsable($keyId)
                : in_array($keyId, $usableKeyIds, true);
        }

        return $state === 'unsigned_acknowledged';
    }

    /**
     * @param array<int, mixed> $keyIds
     *
     * @return array<int, string>
     */
    private function usableKeyIdsFor(array $keyIds): array
    {
        $signature = app(ExtensionSignatureService::class);

        return $signature->signingRequired()
            ? $signature->usableTrustedKeyIds(array_values(array_filter($keyIds, 'is_string')))
            : [];
    }

    private function unverifiedCapabilitiesAllowed(string $state, ExtensionCapabilitySet $capabilities): bool
    {
        if ($state !== 'unsigned_acknowledged'
            || !app(ExtensionSignatureService::class)->signingRequired()) {
            return true;
        }

        return app(ExtensionSignatureService::class)
            ->restrictedCapabilitiesForUnverified($capabilities) === [];
    }

    /**
     * Rebuild a capability set from a stored projection.
     *
     * Public because the update service diffs a new release against the
     * currently-installed set: the stored projection is the only record of what
     * an administrator previously approved.
     */
    public function hydrateCapabilities($capabilities): ?ExtensionCapabilitySet
    {
        return $this->hydrate($capabilities);
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
            // Absent on every package installed before privileged services
            // existed, which is exactly why the projection omits the key when
            // nothing was asked for — see ExtensionCapabilitySet::jsonSerialize.
            privileged: array_values(array_intersect(
                ExtensionCapabilityVocabulary::PRIVILEGED,
                array_map('strval', (array) ($capabilities['privileged'] ?? [])),
            )),
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
