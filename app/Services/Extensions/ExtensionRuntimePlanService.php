<?php

namespace Everest\Services\Extensions;

use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Everest\Services\Queue\QueueTopology;
use Everest\Services\Extensions\Manifest\ExtensionManifest;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\Definitions\HookDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PageDefinition;
use Everest\Services\Extensions\Manifest\Definitions\QueueDefinition;
use Everest\Services\Extensions\Manifest\Definitions\SecretDefinition;
use Everest\Services\Extensions\Manifest\Definitions\StreamDefinition;
use Everest\Services\Extensions\Manifest\Definitions\SettingDefinition;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityVocabulary;
use Everest\Services\Extensions\Manifest\Definitions\VisibilityCondition;
use Everest\Services\Extensions\Manifest\Definitions\PackageFlagPredicate;
use Everest\Services\Extensions\Manifest\Definitions\PermissionDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PackageFlagDefinition;
use Everest\Services\Extensions\Manifest\Definitions\FrontendSlotDefinition;

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

    public function __construct(private ExtensionPackageIntegrityService $integrityService)
    {
    }

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
                    'extension_packages.id',
                    'extension_packages.extension_id',
                    'extension_packages.installed_version',
                    'extension_packages.state',
                    'extension_packages.state_reason',
                    'extension_packages.package_checksum',
                    'extension_packages.manifest',
                    'extension_packages.signed_manifest',
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
            'bindings' => $entry->capabilities->bindings !== [],
            'streams' => $entry->capabilities->streams !== [],
            'slots' => $entry->capabilities->slots !== [],
            default => str_starts_with($capability, 'privileged.')
                && $entry->capabilities->grantsPrivilege(substr($capability, 11)),
        });
    }

    /**
     * The declared limits for one stream kind, or null when the package is not
     * enabled or never declared that name.
     *
     * Read from the live plan on every open rather than cached on the
     * connection, so disabling a package stops it opening new streams even
     * while an old worker is still serving one.
     */
    public function streamFor(string $extensionId, string $name): ?StreamDefinition
    {
        $entry = $this->plan()[$extensionId] ?? null;

        return $entry?->capabilities->streamNamed($name);
    }

    /**
     * Whether an installed, loadable package holds one of core's privileged
     * services — see {@see ExtensionCapabilityVocabulary::PRIVILEGED}.
     *
     * Reads the runtime plan rather than the manifest on disk, so a package
     * that is disabled, unsigned, on the wrong manifest version, or whose
     * capability projection no longer matches its approved hash holds nothing.
     */
    public function grantsPrivilege(string $extensionId, string $privilege): bool
    {
        $entry = $this->plan()[$extensionId] ?? null;

        return $entry !== null && $entry->capabilities->grantsPrivilege($privilege);
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
                    'extension_packages.id',
                    'extension_packages.extension_id',
                    'extension_packages.installed_version',
                    'extension_packages.state',
                    'extension_packages.state_reason',
                    'extension_packages.package_checksum',
                    'extension_packages.manifest',
                    'extension_packages.signed_manifest',
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

        if (!in_array($package->state, self::EXECUTABLE_STATES, true)
            && !$this->integrityService->isIntegrityQuarantined($package)) {
            return 'state';
        }

        if ((int) $package->manifest_version !== ExtensionManifest::VERSION) {
            return 'manifest_version';
        }

        if (!$this->signatureStateAllowed((string) $package->signature_state, $package->signature_key_id)) {
            return 'signature';
        }

        $manifest = $this->integrityService->enforce($package);
        if ($manifest === null) {
            return 'file_integrity';
        }

        $storedCapabilities = $this->hydrate($package->capabilities);
        if ($storedCapabilities === null) {
            return 'capabilities_missing';
        }

        $capabilities = $manifest->capabilities;

        if (!$this->unverifiedCapabilitiesAllowed((string) $package->signature_state, $capabilities)) {
            return 'signature';
        }

        if (!$this->capabilityProjectionMatches($package, $storedCapabilities, $capabilities)) {
            return 'capability_hash';
        }

        return null;
    }

    /**
     * Decide whether one installed package may load, deriving its authority
     * from the authenticated retained manifest.
     */
    private function entryFor(ExtensionPackage $package, ?array $usableKeyIds = null): ?ExtensionRuntimeEntry
    {
        if (!in_array($package->state, self::EXECUTABLE_STATES, true)
            && !$this->integrityService->isIntegrityQuarantined($package)) {
            return null;
        }

        if ((int) $package->manifest_version !== ExtensionManifest::VERSION) {
            return null;
        }

        if (!$this->signatureStateAllowed((string) $package->signature_state, $package->signature_key_id, $usableKeyIds)) {
            return null;
        }

        // This is the last gate before any route file is required or package
        // class is autoloaded. Expected checksums come from a freshly parsed,
        // re-verified signed manifest rather than mutable tracking rows.
        $manifest = $this->integrityService->enforce($package);
        if ($manifest === null) {
            return null;
        }

        $storedCapabilities = $this->hydrate($package->capabilities);
        if ($storedCapabilities === null) {
            return null;
        }

        // Runtime authority comes from the freshly reparsed and reverified
        // retained manifest, never from the adjacent database projection. The
        // projection and its hash remain useful consistency checks, but a
        // writer who changes both still cannot grant a capability that was not
        // publisher-signed.
        $capabilities = $manifest->capabilities;

        // Defense in depth for packages installed before the unsigned policy
        // was tightened. A stale unsigned_acknowledged row cannot keep loading
        // routes, pages, hooks, jobs or any other executable contribution.
        if (!$this->unverifiedCapabilitiesAllowed((string) $package->signature_state, $capabilities)) {
            return null;
        }

        if (!$this->capabilityProjectionMatches($package, $storedCapabilities, $capabilities)) {
            return null;
        }

        return new ExtensionRuntimeEntry($package->extension_id, (string) $package->installed_version, $capabilities);
    }

    private function capabilityProjectionMatches(
        ExtensionPackage $package,
        ExtensionCapabilitySet $stored,
        ExtensionCapabilitySet $authenticated,
    ): bool {
        // Legacy rows with no digest used to bypass this check. Every
        // executable v3 package now needs complete metadata, and both adjacent
        // values must agree with the publisher-authenticated manifest.
        if (!is_string($package->capability_hash) || $package->capability_hash === '') {
            return false;
        }

        return hash_equals($authenticated->hash(), $package->capability_hash)
            && hash_equals($authenticated->hash(), $stored->hash());
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
        $hydratedSettings = array_map(
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
        );
        // Conditions name other fields, so they attach once every field is
        // known -- the same two passes the parser makes.
        foreach ((array) ($settings['fields'] ?? []) as $index => $f) {
            if (isset($hydratedSettings[$index]) && isset($f['visibleWhen'])) {
                $hydratedSettings[$index] = $hydratedSettings[$index]->withVisibleWhen(
                    $this->hydrateVisibleWhen($f['visibleWhen'], $hydratedSettings)
                );
            }
        }
        $hydratedSecrets = array_map(
            fn (array $s): SecretDefinition => new SecretDefinition(
                (string) $s['key'],
                (string) $s['labelKey'],
                isset($s['helpKey']) ? (string) $s['helpKey'] : null,
                (bool) ($s['rotatable'] ?? true),
                isset($s['visibleWhen']) ? $this->hydrateVisibleWhen($s['visibleWhen'], $hydratedSettings) : null,
            ),
            (array) ($capabilities['secrets'] ?? [])
        );
        $hydratedFlags = $this->hydratePackageFlags(
            (array) ($capabilities['flags'] ?? []),
            $hydratedSettings,
            $hydratedSecrets,
        );
        $flagNames = array_map(fn (PackageFlagDefinition $flag): string => $flag->name, $hydratedFlags);

        return new ExtensionCapabilitySet(
            clientRoutes: (bool) ($routes['client'] ?? false),
            adminRoutes: (bool) ($routes['admin'] ?? false),
            serverPages: $this->hydratePages((array) ($pages['server'] ?? []), $flagNames),
            adminPages: $this->hydratePages((array) ($pages['admin'] ?? []), $flagNames),
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
            // Re-bounded on the way out of storage, for the same reason the
            // streams below are: this number decides how long a queue worker is
            // held, and the stored projection is the part of a package someone
            // with database access could edit. It also moves on its own — an
            // operator who lowers QUEUE_RETRY_AFTER after install tightens every
            // installed package here, without reinstalling any of them. The
            // parser refuses an over-budget timeout at install; by the time it
            // is in storage there is nobody left to tell, so this clamps.
            queues: array_map(
                fn (array $q): QueueDefinition => new QueueDefinition(
                    name: (string) $q['name'],
                    maxAttempts: (int) ($q['maxAttempts'] ?? 3),
                    timeoutSeconds: max(1, min(
                        (int) ($q['timeoutSeconds'] ?? 60),
                        $this->queueTimeoutCeiling((bool) ($q['longRunning'] ?? false)),
                    )),
                    longRunning: (bool) ($q['longRunning'] ?? false),
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
            secrets: $hydratedSecrets,
            settings: $hydratedSettings,
            // Absent on every package installed before privileged services
            // existed, which is exactly why the projection omits the key when
            // nothing was asked for — see ExtensionCapabilitySet::jsonSerialize.
            privileged: array_values(array_intersect(
                ExtensionCapabilityVocabulary::PRIVILEGED,
                array_map('strval', (array) ($capabilities['privileged'] ?? [])),
            )),
            // Re-validated on the way out of storage as well as on the way in:
            // this is read to build a class name, and the projection is the one
            // part of a package an attacker with database access could edit.
            bindings: array_values(array_filter(
                array_map('strval', (array) ($capabilities['bindings'] ?? [])),
                fn (string $path): bool => (bool) preg_match(ExtensionCapabilityVocabulary::BINDING_PATTERN, $path),
            )),
            // Re-bounded on the way out for the same reason as the binding
            // pattern above: these numbers decide how long a PHP worker is held,
            // and the stored projection is the part of a package that someone
            // with database access could edit. Clamped rather than dropped — a
            // tampered ceiling becomes the honest one, not an absent limit.
            streams: array_map(
                fn (array $s): StreamDefinition => new StreamDefinition(
                    name: (string) $s['name'],
                    maxSeconds: max(1, min((int) ($s['maxSeconds'] ?? 300), ExtensionCapabilityVocabulary::STREAM_MAX_SECONDS)),
                    keepAliveSeconds: max(1, min((int) ($s['keepAliveSeconds'] ?? 15), ExtensionCapabilityVocabulary::STREAM_MAX_KEEPALIVE_SECONDS)),
                    maxConcurrentPerUser: max(1, min((int) ($s['maxConcurrentPerUser'] ?? 2), ExtensionCapabilityVocabulary::STREAM_MAX_CONCURRENT_PER_USER)),
                ),
                (array) ($capabilities['streams'] ?? [])
            ),
            // Names are intersected with the closed vocabulary and entries are
            // re-checked as slugs. The generated frontend manifest is written
            // from the install-time parse, but hydration must still fail closed
            // if the stored projection is edited later.
            slots: array_values(array_filter(array_map(
                function ($slot) use ($flagNames): ?FrontendSlotDefinition {
                    if (!is_array($slot)) {
                        return null;
                    }

                    $name = (string) ($slot['name'] ?? '');
                    $entry = (string) ($slot['entry'] ?? '');

                    if (!in_array($name, ExtensionCapabilityVocabulary::FRONTEND_SLOTS, true)
                        || !preg_match(ExtensionCapabilityVocabulary::SLUG_PATTERN, $entry)) {
                        return null;
                    }

                    return new FrontendSlotDefinition(
                        name: $name,
                        entry: $entry,
                        order: (int) ($slot['order'] ?? 100),
                        requiredServerPermission: isset($slot['requiredServerPermission'])
                            ? (string) $slot['requiredServerPermission']
                            : null,
                        requiredFlags: $this->hydrateRequiredFlags($slot['requiredFlags'] ?? [], $flagNames),
                    );
                },
                (array) ($capabilities['slots'] ?? [])
            ))),
            flags: $hydratedFlags,
        );
    }

    /**
     * The longest a stored queue definition may claim, for the lane it rides.
     *
     * The absolute ceiling and this deployment's `retry_after`, lower wins. On
     * a driver with no `retry_after` (`sync`, `sqs`) only the absolute one
     * applies.
     */
    private function queueTimeoutCeiling(bool $longRunning): int
    {
        $lane = $longRunning ? QueueDefinition::LONG_LANE : QueueDefinition::LANE;
        $lanes = app(QueueTopology::class)->maxJobTimeoutFor($lane);

        return min(
            ExtensionCapabilityVocabulary::QUEUE_MAX_TIMEOUT_SECONDS,
            $lanes ?? ExtensionCapabilityVocabulary::QUEUE_MAX_TIMEOUT_SECONDS,
        );
    }

    /**
     * Rehydrate only predicates the stored projection could have received from
     * the strict manifest parser. Anything else is dropped, changing the
     * recomputed capability hash and making a tampered package inert.
     *
     * @param array<int, mixed> $flags
     * @param array<int, SettingDefinition> $settings
     * @param array<int, SecretDefinition> $secrets
     *
     * @return array<int, PackageFlagDefinition>
     */
    private function hydratePackageFlags(array $flags, array $settings, array $secrets): array
    {
        $settingMap = [];
        foreach ($settings as $setting) {
            $settingMap[$setting->key] = $setting;
        }
        $secretKeys = array_fill_keys(
            array_map(fn (SecretDefinition $secret): string => $secret->key, $secrets),
            true
        );

        $hydrated = [];
        $seen = [];
        foreach (array_slice($flags, 0, ExtensionCapabilityVocabulary::MAX_FRONTEND_FLAGS) as $flag) {
            if (!is_array($flag)) {
                continue;
            }

            $name = (string) ($flag['name'] ?? '');
            if (!preg_match(ExtensionCapabilityVocabulary::SLUG_PATTERN, $name) || isset($seen[$name])) {
                continue;
            }

            $all = $this->hydrateFlagPredicates($flag['all'] ?? [], $settingMap, $secretKeys);
            $any = $this->hydrateFlagPredicates($flag['any'] ?? [], $settingMap, $secretKeys);
            if ($all === [] && $any === []) {
                continue;
            }

            $seen[$name] = true;
            $hydrated[] = new PackageFlagDefinition($name, $all, $any);
        }

        usort($hydrated, fn (PackageFlagDefinition $a, PackageFlagDefinition $b): int => strcmp($a->name, $b->name));

        return $hydrated;
    }

    /**
     * @param array<string, SettingDefinition> $settings
     * @param array<string, true> $secrets
     *
     * @return array<int, PackageFlagPredicate>
     */
    private function hydrateFlagPredicates(mixed $predicates, array $settings, array $secrets): array
    {
        if (!is_array($predicates) || !array_is_list($predicates)) {
            return [];
        }

        $hydrated = [];
        $seen = [];
        foreach (array_slice($predicates, 0, ExtensionCapabilityVocabulary::MAX_FLAG_PREDICATES) as $predicate) {
            if (!is_array($predicate)) {
                continue;
            }

            $hasSetting = isset($predicate['setting']) && is_string($predicate['setting']);
            $hasSecret = isset($predicate['secret']) && is_string($predicate['secret']);
            if ($hasSetting === $hasSecret) {
                continue;
            }

            $operator = array_key_exists(PackageFlagPredicate::OPERATOR_EQUALS, $predicate)
                ? PackageFlagPredicate::OPERATOR_EQUALS
                : (array_key_exists(PackageFlagPredicate::OPERATOR_CONFIGURED, $predicate)
                    ? PackageFlagPredicate::OPERATOR_CONFIGURED
                    : null);
            if ($operator === null
                || (array_key_exists(PackageFlagPredicate::OPERATOR_EQUALS, $predicate)
                    && array_key_exists(PackageFlagPredicate::OPERATOR_CONFIGURED, $predicate))) {
                continue;
            }

            $source = $hasSetting ? PackageFlagPredicate::SOURCE_SETTING : PackageFlagPredicate::SOURCE_SECRET;
            $key = (string) $predicate[$source];
            $expected = $predicate[$operator];

            if ($source === PackageFlagPredicate::SOURCE_SECRET) {
                if ($operator !== PackageFlagPredicate::OPERATOR_CONFIGURED
                    || !isset($secrets[$key])
                    || !is_bool($expected)) {
                    continue;
                }
            } else {
                $setting = $settings[$key] ?? null;
                if ($setting === null || $setting->isSecret()) {
                    continue;
                }
                if ($operator === PackageFlagPredicate::OPERATOR_CONFIGURED) {
                    if (!is_bool($expected)) {
                        continue;
                    }
                } elseif (!$this->flagExpectedMatchesSetting($expected, $setting)) {
                    continue;
                }
            }

            /** @var string|int|float|bool $expected */
            $item = new PackageFlagPredicate($source, $key, $operator, $expected);
            $signature = json_encode($item->jsonSerialize(), JSON_THROW_ON_ERROR);
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $hydrated[] = $item;
        }

        usort($hydrated, fn (PackageFlagPredicate $a, PackageFlagPredicate $b): int => strcmp(
            json_encode($a->jsonSerialize(), JSON_THROW_ON_ERROR),
            json_encode($b->jsonSerialize(), JSON_THROW_ON_ERROR),
        ));

        return $hydrated;
    }

    /**
     * A stored `visibleWhen`, re-read with the same leniency as a flag: a
     * predicate that no longer names a readable setting is dropped rather than
     * failing the whole package, and a condition left with nothing in it means
     * "always shown".
     *
     * @param array<int, SettingDefinition> $settings
     */
    private function hydrateVisibleWhen(mixed $condition, array $settings): ?VisibilityCondition
    {
        if (!is_array($condition)) {
            return null;
        }

        $settingMap = [];
        foreach ($settings as $setting) {
            $settingMap[$setting->key] = $setting;
        }

        $all = $this->hydrateFlagPredicates($condition['all'] ?? [], $settingMap, []);
        $any = $this->hydrateFlagPredicates($condition['any'] ?? [], $settingMap, []);

        return $all === [] && $any === [] ? null : new VisibilityCondition($all, $any);
    }

    private function flagExpectedMatchesSetting(mixed $expected, SettingDefinition $setting): bool
    {
        return match ($setting->type) {
            'boolean' => is_bool($expected),
            'number' => is_int($expected) || is_float($expected),
            default => is_string($expected),
        };
    }

    /**
     * @param array<int, string> $declared
     *
     * @return array<int, string>
     */
    private function hydrateRequiredFlags(mixed $required, array $declared): array
    {
        if (!is_array($required) || !array_is_list($required)) {
            return [];
        }

        $flags = array_values(array_unique(array_filter(
            $required,
            fn (mixed $flag): bool => is_string($flag) && in_array($flag, $declared, true),
        )));
        sort($flags, SORT_STRING);

        return $flags;
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @param array<int, string> $flagNames
     *
     * @return array<int, PageDefinition>
     */
    private function hydratePages(array $pages, array $flagNames): array
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
                requiredFlags: $this->hydrateRequiredFlags($page['requiredFlags'] ?? [], $flagNames),
            ),
            $pages
        );
    }
}
