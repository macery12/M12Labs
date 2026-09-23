<?php

namespace Everest\Services\Extensions;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use GuzzleHttp\Psr7\UriResolver;
use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Illuminate\Support\Facades\Cache;
use Everest\Models\ExtensionRepository;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\Manifest\ExtensionManifest;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;

class ExtensionCatalogService
{
    public function __construct(
        private ExtensionRepositoryBootstrapService $bootstrapService,
        private ExtensionMigrationService $migrationService,
        private ExtensionPackageArtifactService $artifactService,
        private ExtensionRuntimePlanService $planService,
        private ExtensionSignatureService $signatureService,
        private ExtensionRemoteUrlGuard $urlGuard,
        private ExtensionRemoteResourceService $remoteResourceService,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLocalExtensions(): array
    {
        $configs = ExtensionConfig::query()->get()->keyBy('extension_id');
        $extensions = [];

        foreach ((array) config('modules.extensions.available', []) as $extensionId => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $config = $configs->get($extensionId);
            $extensions[$extensionId] = [
                'id' => $extensionId,
                'name' => $definition['name'] ?? $extensionId,
                'description' => $definition['description'] ?? '',
                'version' => $definition['version'] ?? '1.0.0',
                'latestVersion' => $definition['version'] ?? '1.0.0',
                'author' => $definition['author'] ?? 'M12Labs',
                'icon' => $definition['icon'] ?? 'puzzle',
                'route' => $definition['route'] ?? $extensionId,
                'hasServerPage' => true,
                'admin' => null,
                'type' => 'user',
                'enabled' => (bool) ($config?->enabled ?? false),
                'allowedNests' => array_values($config?->allowed_nests ?? $definition['allowed_nests'] ?? []),
                'allowedEggs' => array_values($config?->allowed_eggs ?? $definition['allowed_eggs'] ?? []),
                'settings' => is_array($config?->settings) ? $config->settings : [],
                'settingsSchema' => $this->normalizeSettingsSchema($definition['settings_schema'] ?? []),
                'installed' => true,
                'installable' => false,
                'canUninstall' => false,
                'hasDatabase' => false,
                'status' => 'core',
                'updateAvailable' => false,
                // Core + already-installed extensions are never gated on
                // compatibility — they're on disk and running.
                'compatible' => true,
                'compatiblePanelVersions' => [],
                'source' => [
                    'type' => 'core',
                    'label' => 'Core',
                    'official' => true,
                    'repositoryId' => null,
                    'repositoryName' => 'Core',
                    'homepageUrl' => null,
                    'securityWarning' => 'Core extensions ship with M12Labs itself and are not removed through the repository installer.',
                ],
            ];
        }

        $packages = ExtensionPackage::query()->with('repository')->get();
        foreach ($packages as $package) {
            $config = $configs->get($package->extension_id);
            $manifest = is_array($package->manifest) ? $package->manifest : [];
            $extension = (array) Arr::get($manifest, 'extension', []);
            $repository = $package->repository;

            // v3 packages declare pages as capabilities; the surfaces are read
            // from the verified projection rather than from manifest prose.
            // The v2 shape (`extension.route` / `extension.admin`) is still read
            // for a package that predates the projection — those are
            // quarantined and cannot run, but the catalog still has to render
            // them so an operator can see what to uninstall.
            $capabilities = is_array($package->capabilities)
                ? $this->planService->hydrateCapabilities($package->capabilities)
                : null;

            $hasServerPage = $capabilities !== null
                ? $capabilities->hasServerPages()
                : Arr::get($extension, 'route', $package->route ?: $package->extension_id) !== null;

            $hasAdminPage = $capabilities !== null
                ? $capabilities->hasAdminPages()
                : (is_array(Arr::get($extension, 'admin')) && Arr::get($extension, 'admin') !== []);

            $adminSurface = Arr::get($extension, 'admin');

            $extensions[$package->extension_id] = [
                'id' => $package->extension_id,
                'name' => $package->name,
                'description' => $package->description ?? '',
                'version' => $package->installed_version,
                'latestVersion' => $package->installed_version,
                'author' => $package->author ?? 'M12Labs',
                'icon' => $package->icon ?: 'puzzle',
                'route' => $package->route ?: $package->extension_id,
                // Where a v3 package's primary server page actually lives. The
                // legacy `route` above is the v2 shape (extensions/<route>) and
                // resolves to nothing for a v3 package, whose pages the loader
                // mounts at extensions/ext/<id>/<slug>.
                'serverPagePath' => $this->primaryServerPagePath($package->extension_id, $capabilities),
                'adminSettingsPath' => $this->adminSettingsPath($package->extension_id, $capabilities),
                'hasServerPage' => $hasServerPage,
                'admin' => $adminSurface,
                'type' => $this->deriveExtensionType($hasServerPage, $hasAdminPage),
                'enabled' => (bool) ($config?->enabled ?? false),
                'allowedNests' => array_values($config?->allowed_nests ?? Arr::get($extension, 'defaults.allowedNests', [])),
                'allowedEggs' => array_values($config?->allowed_eggs ?? Arr::get($extension, 'defaults.allowedEggs', [])),
                'settings' => is_array($config?->settings)
                    ? $config->settings
                    : (array) Arr::get($extension, 'defaults.settings', []),
                'settingsSchema' => $capabilities !== null
                    ? $this->projectSettingsSchema($capabilities)
                    : $this->normalizeSettingsSchema(Arr::get($extension, 'settingsSchema', [])),
                'installed' => true,
                'installable' => false,
                'canUninstall' => true,
                // Persisted lifecycle state. "unsupported" marks a package built
                // for a manifest version this panel no longer accepts: it is inert
                // and cannot be enabled, so the UI offers Uninstall only.
                'state' => $package->state,
                'stateReason' => $package->state_reason,
                'manifestVersion' => (int) $package->manifest_version,
                'canEnable' => in_array($package->state, ['enabled', 'installed_disabled'], true),
                // Whether this package ships a database (migrations). Drives
                // whether the uninstall UI offers the drop-tables option, so the
                // operator is never asked about data an extension never created.
                'hasDatabase' => $this->migrationService->hasMigrations($package->extension_id),
                'status' => $package->state === 'unsupported' ? 'unsupported' : 'installed',
                'updateAvailable' => false,
                // Installed packages (including manual uploads that may sit outside
                // the declared range) are already on disk and never blocked.
                'compatible' => true,
                'compatiblePanelVersions' => array_values(array_filter((array) Arr::get($manifest, 'compatiblePanelVersions', []), 'is_string')),
                'source' => [
                    'type' => 'repository',
                    'label' => $package->source_repository_name ?: ($repository?->name ?? 'Custom repository'),
                    'official' => (bool) $repository?->is_official,
                    'repositoryId' => $repository?->id,
                    'repositoryName' => $package->source_repository_name ?: $repository?->name,
                    'homepageUrl' => $repository?->homepage_url,
                    'securityWarning' => $this->getRepositorySecurityWarning($repository),
                ],
            ];
        }

        ksort($extensions);

        return array_values($extensions);
    }

    /**
     * @return array{extensions: array<int, array<string, mixed>>, repositories: array<int, array<string, mixed>>}
     */
    public function getCatalog(bool $forceRefresh = false): array
    {
        $this->bootstrapService->ensureOfficialRepository();

        $localExtensions = [];
        foreach ($this->getLocalExtensions() as $extension) {
            $localExtensions[$extension['id']] = $extension;
        }

        $configs = ExtensionConfig::query()->get()->keyBy('extension_id');
        $repositories = [];

        foreach (ExtensionRepository::query()->orderByDesc('is_official')->orderBy('name')->get() as $repository) {
            $repositorySummary = $this->formatRepositorySummary($repository);

            if (!$repository->enabled) {
                $repositorySummary['status'] = 'disabled';
                $repositories[] = $repositorySummary;

                continue;
            }

            try {
                $manifest = $this->fetchRepositoryManifest($repository, $forceRefresh);
                $packages = $manifest['packages'] ?? [];

                $repositorySummary['status'] = 'ok';
                $repositorySummary['packagesCount'] = count($packages);

                foreach ($packages as $package) {
                    $extensionId = $package['id'];
                    // Surface the newest release the running panel can actually
                    // install — not just the newest overall. A package may ship
                    // (e.g.) a 2.0.0 for the current panel and an older 1.0.0 for
                    // a previous one; picking blindly by recency would flag the
                    // whole extension incompatible whenever the newest build
                    // targets a different panel version.
                    $latestRelease = $this->selectInstallableRelease($package['versions'], $extensionId);
                    $config = $configs->get($extensionId);

                    if (isset($localExtensions[$extensionId])) {
                        $localExtension = $localExtensions[$extensionId];
                        $installedRepositoryId = Arr::get($localExtension, 'source.repositoryId');

                        // An installed package keeps using the repository it was
                        // installed from. A second repository may publish the same
                        // extension id, but must not silently become its update
                        // source (or advertise a version the selected source does
                        // not contain).
                        if (($localExtension['status'] ?? null) !== 'core'
                            && $installedRepositoryId !== null
                            && (int) $installedRepositoryId !== (int) $repository->id
                        ) {
                            continue;
                        }

                        // Only offer an update when the repository release is
                        // strictly NEWER than what's installed. A plain `!==`
                        // check mis-fires when a manually installed build is ahead
                        // of the repo (e.g. local 2.0.0 vs published 1.0.0) and
                        // would otherwise advertise a downgrade as an "update".
                        //
                        // 'unsupported' is included deliberately: a package
                        // quarantined for its manifest version is exactly the
                        // one that needs updating, and a newer release is the
                        // only way out that keeps its data. Excluding it left
                        // every pre-v3 install with uninstall as the sole
                        // option.
                        $updateAvailable =
                            in_array($localExtension['status'], ['installed', 'core', 'unsupported'], true)
                            && version_compare(
                                (string) $latestRelease['version'],
                                (string) $localExtension['version'],
                                '>'
                            );

                        // Legacy and manual-file installs have no repository FK.
                        // Once an enabled repository publishes a newer release
                        // with the same extension id, expose that repository as
                        // the update source. This is what lets a quarantined v1
                        // package move directly to v3 through the panel instead
                        // of requiring a manual uninstall or intermediate v2
                        // install. Repositories are ordered official-first, so
                        // the first eligible source wins deterministically.
                        if (($localExtension['status'] ?? null) !== 'core'
                            && $installedRepositoryId === null
                            && !$updateAvailable
                        ) {
                            continue;
                        }

                        $localExtension['latestVersion'] = $latestRelease['version'];
                        $localExtension['compatiblePanelVersions'] = $latestRelease['compatiblePanelVersions'];
                        $localExtension['updateAvailable'] = $updateAvailable;

                        if ($installedRepositoryId === null && $updateAvailable) {
                            $localExtension['source'] = $this->repositorySource($repository);
                        }

                        if ($this->shouldMirrorCoreExtensionFromRepository($localExtension, $repository)) {
                            $localExtension = $this->mirrorCoreExtensionFromRepository(
                                $localExtension,
                                $repository
                            );
                        }

                        $localExtensions[$extensionId] = $localExtension;

                        continue;
                    }

                    $localExtensions[$extensionId] = [
                        'id' => $extensionId,
                        'name' => $package['name'],
                        'description' => $package['description'],
                        'version' => $latestRelease['version'],
                        'latestVersion' => $latestRelease['version'],
                        'author' => $package['author'],
                        'icon' => $package['icon'],
                        // Registry metadata is untrusted until the signed
                        // artifact is verified at install, so nothing here is a
                        // capability claim the panel acts on. `route` is gone
                        // entirely — routes are derived by the loader from the
                        // verified manifest — and the surface counts below are
                        // an advertisement rendered on the catalog card, marked
                        // as such in the UI.
                        'capabilitySummary' => $package['capabilitySummary'],
                        'hasServerPage' => ($package['capabilitySummary']['serverPages'] ?? 0) > 0,
                        'admin' => null,
                        'type' => $this->deriveExtensionType(
                            ($package['capabilitySummary']['serverPages'] ?? 0) > 0,
                            ($package['capabilitySummary']['adminPages'] ?? 0) > 0,
                        ),
                        'enabled' => false,
                        'allowedNests' => array_values($config?->allowed_nests ?? []),
                        'allowedEggs' => array_values($config?->allowed_eggs ?? []),
                        'settings' => is_array($config?->settings) ? $config->settings : [],
                        // An uninstalled package has no verified schema to
                        // render, and the repository's copy is not one. The
                        // settings form appears after install.
                        'settingsSchema' => [],
                        'installed' => false,
                        'installable' => true,
                        'canUninstall' => false,
                        'hasDatabase' => false,
                        'status' => 'available',
                        'updateAvailable' => false,
                        // A repository fetch is gated on the panel version: an
                        // incompatible release surfaces as "incompatible" and the
                        // install button is blocked (the install service enforces
                        // the same rule server-side).
                        'compatible' => $this->artifactService->isCompatiblePanelVersions($latestRelease['compatiblePanelVersions'] ?? []),
                        'compatiblePanelVersions' => $latestRelease['compatiblePanelVersions'],
                        'source' => $this->repositorySource($repository),
                    ];
                }
            } catch (\Throwable $exception) {
                report($exception);

                $repositorySummary['status'] = 'error';
                $repositorySummary['error'] = $exception->getMessage();
            }

            $repositories[] = $repositorySummary;
        }

        ksort($localExtensions);

        return [
            'extensions' => array_values($localExtensions),
            'repositories' => $repositories,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExtension(string $extensionId, bool $forceRefresh = false): ?array
    {
        foreach ($this->getCatalog($forceRefresh)['extensions'] as $extension) {
            if ($extension['id'] === $extensionId) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRepositories(bool $forceRefresh = false): array
    {
        return $this->getCatalog($forceRefresh)['repositories'];
    }

    /**
     * @return array{id: string, name: string, description: string, author: string, icon: string, route: string, settingsSchema: array<int, array<string, mixed>>, latestRelease: array<string, mixed>, repository: ExtensionRepository}
     */
    public function findRepositoryPackage(string $extensionId, int $repositoryId, ?string $version = null): array
    {
        $this->bootstrapService->ensureOfficialRepository();

        $repository = ExtensionRepository::query()->findOrFail($repositoryId);
        if (!$repository->enabled) {
            throw new DisplayException('The selected extension repository is disabled.');
        }

        $manifest = $this->fetchRepositoryManifest($repository, true);

        foreach ($manifest['packages'] ?? [] as $package) {
            if ($package['id'] !== $extensionId) {
                continue;
            }

            if ($version === null || $package['latestRelease']['version'] === $version) {
                $package['repository'] = $repository;

                return $package;
            }

            foreach ($package['versions'] as $release) {
                if ($release['version'] === $version) {
                    $package['latestRelease'] = $release;
                    $package['repository'] = $repository;

                    return $package;
                }
            }
        }

        throw new DisplayException('The selected extension could not be found in that repository.');
    }

    public function validateRepository(ExtensionRepository $repository): void
    {
        $this->urlGuard->assertSafeHttpsUrl($repository->manifest_url, 'Repository manifest URL');

        if (!$repository->enabled) {
            return;
        }

        $this->fetchRepositoryManifest($repository, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchRepositoryManifest(ExtensionRepository $repository, bool $forceRefresh = false): array
    {
        $this->urlGuard->assertSafeHttpsUrl($repository->manifest_url, 'Repository manifest URL');

        $cacheKey = sprintf(
            'extensions:repository:%s:%s',
            $repository->id,
            sha1($repository->manifest_url . '|' . $repository->updated_at?->timestamp)
        );

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($repository) {
            $payload = json_decode($this->readLocationContents($repository->manifest_url), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new DisplayException(sprintf('Repository "%s" returned an invalid manifest.', $repository->name));
            }

            return $this->normalizeRepositoryManifest($payload, $repository);
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function normalizeRepositoryManifest(array $payload, ExtensionRepository $repository): array
    {
        // Release keys travel with the registry but are trusted only once the
        // pinned offline root's signature over each key record verifies. Doing
        // this on every refresh is also how a revocation reaches the panel.
        if (isset($payload['keys']) && is_array($payload['keys'])) {
            $this->signatureService->syncRegistryKeys($payload['keys'], $repository->id);
            $this->signatureService->markRevokedInstalls();
        }

        $packages = [];
        foreach ((array) ($payload['packages'] ?? []) as $package) {
            if (!is_array($package)) {
                continue;
            }

            $extensionId = trim((string) ($package['id'] ?? ''));
            if ($extensionId === '') {
                continue;
            }

            $versions = [];
            foreach ((array) ($package['versions'] ?? []) as $release) {
                if (!is_array($release)) {
                    continue;
                }

                $version = trim((string) ($release['version'] ?? ''));
                $archive = trim((string) ($release['archive'] ?? ''));
                $checksum = $this->normalizeChecksum((string) ($release['sha256'] ?? ''));

                if ($version === '' || $archive === '' || $checksum === '') {
                    continue;
                }

                $archiveUrl = $this->resolveLocation($repository->manifest_url, $archive);
                $this->urlGuard->assertSafeHttpsUrl($archiveUrl, sprintf('Archive URL for extension "%s"', $extensionId));

                $versions[] = [
                    'version' => $version,
                    'archiveUrl' => $archiveUrl,
                    'archiveChecksum' => $checksum,
                    'publishedAt' => $release['publishedAt'] ?? null,
                    'compatiblePanelVersions' => array_values(array_filter((array) ($release['compatiblePanelVersions'] ?? []), 'is_string')),
                    'notes' => $release['notes'] ?? null,
                    // Schema 2 states each release's manifest version, so a
                    // package this panel cannot install is filtered out before
                    // it is offered rather than after it is downloaded. A
                    // schema-1 registry says nothing, and everything it lists
                    // predates manifest v3.
                    'manifestVersion' => isset($release['manifestVersion']) ? (int) $release['manifestVersion'] : null,
                    // A withdrawn release. Advisory only: the panel still
                    // verifies the signature and the key's own revocation at
                    // install, so a registry cannot use this to force anything.
                    'revoked' => (bool) ($release['revoked'] ?? false),
                    'signature' => $this->normalizeReleaseSignature($release['signature'] ?? null),
                ];
            }

            if ($versions === []) {
                continue;
            }

            usort($versions, function (array $left, array $right): int {
                $leftPublishedAt = $left['publishedAt'] ?? '';
                $rightPublishedAt = $right['publishedAt'] ?? '';

                if ($leftPublishedAt !== '' || $rightPublishedAt !== '') {
                    return strcmp((string) $rightPublishedAt, (string) $leftPublishedAt);
                }

                return strcmp((string) $right['version'], (string) $left['version']);
            });

            $packages[] = [
                'id' => $extensionId,
                'name' => (string) ($package['name'] ?? $extensionId),
                'description' => (string) ($package['description'] ?? ''),
                'author' => (string) ($package['author'] ?? 'M12Labs'),
                'icon' => (string) ($package['icon'] ?? 'puzzle'),
                // Display only, and labelled as such in the UI. Schema 2 stopped
                // carrying routes, surfaces, admin blocks and settings schemas
                // because the panel gated on them while a repository could
                // rewrite them at will. Every one of those now comes from the
                // signed manifest inside the archive.
                'capabilitySummary' => $this->normalizeCapabilitySummary($package['capabilitySummary'] ?? null),
                'versions' => $versions,
                'latestRelease' => $versions[0],
            ];
        }

        return [
            'schemaVersion' => (int) ($payload['schemaVersion'] ?? 1),
            'repository' => [
                'name' => (string) Arr::get($payload, 'repository.name', $repository->name),
                'homepage' => Arr::get($payload, 'repository.homepage', $repository->homepage_url),
            ],
            'packages' => $packages,
        ];
    }

    /**
     * A release's detached signature, as advertised by the repository.
     *
     * Kept for display and for the pre-download filter in
     * selectInstallableRelease(). It is never the thing that authorizes an
     * install: the signature that matters travels inside the archive and is
     * checked against the trusted key store after download.
     *
     * @return array{keyId: string, value: string, canonicalManifestSha256: ?string}|null
     */
    private function normalizeReleaseSignature(mixed $signature): ?array
    {
        if (!is_array($signature)) {
            return null;
        }

        $keyId = trim((string) ($signature['keyId'] ?? ''));
        $value = trim((string) ($signature['value'] ?? ''));

        if ($keyId === '' || $value === '') {
            return null;
        }

        return [
            'keyId' => $keyId,
            'value' => $value,
            'canonicalManifestSha256' => isset($signature['canonicalManifestSha256'])
                ? strtolower((string) $signature['canonicalManifestSha256'])
                : null,
        ];
    }

    /**
     * The repository's advertised capability counts, coerced to a fixed shape.
     *
     * Only what a catalog card renders. Booleans and counts rather than the
     * capability block itself, so there is nothing here a caller could mistake
     * for the verified projection.
     *
     * @return array<string, int|bool|array<int, string>>|null
     */
    private function normalizeCapabilitySummary(mixed $summary): ?array
    {
        if (!is_array($summary)) {
            return null;
        }

        return [
            'serverPages' => (int) ($summary['serverPages'] ?? 0),
            'adminPages' => (int) ($summary['adminPages'] ?? 0),
            'clientRoutes' => (bool) ($summary['clientRoutes'] ?? false),
            'adminRoutes' => (bool) ($summary['adminRoutes'] ?? false),
            'migrations' => (bool) ($summary['migrations'] ?? false),
            'schedule' => (bool) ($summary['schedule'] ?? false),
            'commands' => (int) ($summary['commands'] ?? 0),
            'hooks' => array_values(array_filter((array) ($summary['hooks'] ?? []), 'is_string')),
            'queues' => (int) ($summary['queues'] ?? 0),
            'permissions' => (int) ($summary['permissions'] ?? 0),
            'secrets' => (int) ($summary['secrets'] ?? 0),
            'settings' => (int) ($summary['settings'] ?? 0),
            // Names rather than a count, as for hooks: "asks for 1 privileged
            // service" tells an operator nothing they can act on.
            'privileged' => array_values(array_filter((array) ($summary['privileged'] ?? []), 'is_string')),
            'bindings' => (int) ($summary['bindings'] ?? 0),
            'streams' => (int) ($summary['streams'] ?? 0),
            'slots' => (int) ($summary['slots'] ?? 0),
            'flags' => (int) ($summary['flags'] ?? 0),
        ];
    }

    /**
     * Choose which repository release to surface for install/update.
     *
     * Releases arrive newest-first (see the usort in
     * normalizeRepositoryManifest). We prefer the newest one the running panel
     * can actually install, which means skipping four kinds of release the
     * install would reject anyway — offering one produces an install that fails
     * partway rather than a button that is simply absent:
     *
     *   - a manifest version this panel does not parse,
     *   - a release the repository has withdrawn,
     *   - a release signed by a key the panel does not trust or that has
     *     expired or been revoked,
     *   - a version at or below one already installed on this panel, which the
     *     rollback guard refuses.
     *
     * Only when NO release survives do we fall back to the newest overall. The
     * extension then reads as "incompatible" and the install button is blocked,
     * listing that release's requirements.
     *
     * @param array<int, array<string, mixed>> $versions newest-first
     *
     * @return array<string, mixed>
     */
    private function selectInstallableRelease(array $versions, string $extensionId): array
    {
        foreach ($versions as $release) {
            if (!$this->artifactService->isCompatiblePanelVersions($release['compatiblePanelVersions'] ?? [])) {
                continue;
            }

            // A registry that omits manifestVersion is schema 1, and everything
            // it lists predates v3. Treating "unstated" as installable would
            // offer exactly the packages the parser rejects.
            $manifestVersion = $release['manifestVersion'] ?? null;
            if ($manifestVersion !== null && $manifestVersion !== ExtensionManifest::VERSION) {
                continue;
            }

            if ($release['revoked'] ?? false) {
                continue;
            }

            if (!$this->signatureService->isReleaseKeyUsable($release['signature']['keyId'] ?? null)) {
                continue;
            }

            if ($this->signatureService->isRollback($extensionId, (string) $release['version'])) {
                continue;
            }

            return $release;
        }

        return $versions[0];
    }

    /**
     * The path under /admin/ of a package's own settings page, by convention
     * the admin page whose slug is `settings`.
     *
     * A package with more configuration than the generated form can carry
     * ships its own page for it, and the drawer links there rather than
     * leaving an operator to find it in the sidebar. Built the same way as
     * {@see primaryServerPagePath()}: from the verified projection, never a
     * path the package names.
     */
    private function adminSettingsPath(string $extensionId, ?ExtensionCapabilitySet $capabilities): ?string
    {
        foreach ($capabilities?->adminPages ?? [] as $page) {
            if ($page->slug === 'settings') {
                return sprintf('extensions/ext/%s/settings', $extensionId);
            }
        }

        return null;
    }

    /**
     * The URL segment under /server/:id/ for a package's first server page.
     *
     * Derived from the verified capability projection and the extension id, in
     * the same shape frontend/src/pages/server/extensions/registry.ts mounts —
     * a package never names its own path, so a link can never point at a
     * segment it does not own. Null when the package declares no server page,
     * or when it predates the projection.
     */
    private function primaryServerPagePath(string $extensionId, ?ExtensionCapabilitySet $capabilities): ?string
    {
        if ($capabilities === null || $capabilities->serverPages === []) {
            return null;
        }

        $pages = $capabilities->serverPages;
        usort(
            $pages,
            fn ($left, $right): int => $left->order <=> $right->order ?: strcmp($left->slug, $right->slug),
        );

        return sprintf('extensions/ext/%s/%s', $extensionId, $pages[0]->slug);
    }

    /**
     * Derive the extension's surface type from which surfaces it exposes.
     *
     * 'user'  — a per-server page only (the classic surface; nest/egg access
     *           scoping applies).
     * 'admin' — an admin page only; there is no per-server surface, so nest/egg
     *           access scoping is meaningless and is hidden in the UI.
     * 'both'  — exposes both surfaces.
     */
    /**
     * The declared settings fields, in the shape the admin drawer renders.
     *
     * Secret-visibility fields are excluded: they are written through the
     * secret store, and this payload is returned by the catalog API. Internal
     * fields are excluded too -- the package's own settings UI owns them, and
     * the drawer rendering them anyway is how a legacy key nobody reads ends
     * up looking like a control that matters.
     *
     * @return array<int, array<string, mixed>>
     */
    private function projectSettingsSchema(ExtensionCapabilitySet $capabilities): array
    {
        $fields = [];

        foreach ($capabilities->settings as $field) {
            if ($field->isSecret() || $field->isInternal()) {
                continue;
            }

            $fields[] = array_filter([
                'key' => $field->key,
                'type' => $field->type,
                // The label lives in the extension's own translation catalog,
                // which the panel cannot resolve server-side. The key travels
                // and the frontend resolves it; the key itself is the fallback.
                'labelKey' => $field->labelKey,
                'label' => $field->key,
                'helpKey' => $field->helpKey,
                'required' => $field->required,
                'default' => $field->default,
                'options' => $field->enum === null
                    ? null
                    : array_map(fn (string $value): array => ['value' => $value, 'label' => $value], $field->enum),
                'min' => $field->min,
                'max' => $field->max,
                'visibleWhen' => $field->visibleWhen?->jsonSerialize(),
            ], fn ($value) => $value !== null && $value !== false);
        }

        return $fields;
    }

    private function deriveExtensionType(bool $hasServerPage, bool $hasAdminPage): string
    {
        if ($hasServerPage && $hasAdminPage) {
            return 'both';
        }

        return $hasAdminPage ? 'admin' : 'user';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSettingsSchema(mixed $schema): array
    {
        if (!is_array($schema)) {
            return [];
        }

        return array_values(array_filter($schema, function ($field): bool {
            return is_array($field)
                && !empty($field['key'])
                && !empty($field['label'])
                && !empty($field['type']);
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRepositorySummary(ExtensionRepository $repository): array
    {
        return [
            'id' => $repository->id,
            'slug' => $repository->slug,
            'name' => $repository->name,
            'manifestUrl' => $repository->manifest_url,
            'homepageUrl' => $repository->homepage_url,
            'enabled' => $repository->enabled,
            'official' => $repository->is_official,
            'packagesCount' => 0,
            'securityWarning' => $this->getRepositorySecurityWarning($repository),
        ];
    }

    /**
     * @param array<string, mixed> $extension
     */
    private function shouldMirrorCoreExtensionFromRepository(array $extension, ExtensionRepository $repository): bool
    {
        return ($extension['status'] ?? null) === 'core' && $repository->is_official;
    }

    /**
     * @param array<string, mixed> $extension
     *
     * @return array<string, mixed>
     */
    private function mirrorCoreExtensionFromRepository(array $extension, ExtensionRepository $repository): array
    {
        $extension['status'] = 'installed';
        $extension['installed'] = true;
        $extension['installable'] = false;
        $extension['canUninstall'] = false;
        $extension['source'] = $this->repositorySource($repository);

        return $extension;
    }

    /**
     * @return array<string, mixed>
     */
    private function repositorySource(ExtensionRepository $repository): array
    {
        return [
            'type' => 'repository',
            'label' => $repository->name,
            'official' => (bool) $repository->is_official,
            'repositoryId' => $repository->id,
            'repositoryName' => $repository->name,
            'homepageUrl' => $repository->homepage_url,
            'securityWarning' => $this->getRepositorySecurityWarning($repository),
        ];
    }

    private function getRepositorySecurityWarning(?ExtensionRepository $repository): string
    {
        if ($repository?->is_official) {
            return 'Checksums verify that the downloaded archive matches the manifest published by the official M12Labs repository.';
        }

        return 'Third-party repositories can ship arbitrary PHP and frontend code into M12Labs. Checksums only verify the archive matches that repository manifest.';
    }

    private function readLocationContents(string $location): string
    {
        $limits = (array) config('extensions.repository', []);

        return $this->remoteResourceService->getContents(
            $location,
            (int) ($limits['max_manifest_bytes'] ?? 1024 * 1024),
            (int) ($limits['download_timeout_seconds'] ?? 30),
            (int) ($limits['download_connect_timeout_seconds'] ?? 10),
            (int) ($limits['max_redirects'] ?? 3),
            'extension repository manifest',
        );
    }

    private function resolveLocation(string $baseLocation, string $path): string
    {
        if ($path === '') {
            return $path;
        }

        try {
            return (string) UriResolver::resolve(Utils::uriFor($baseLocation), Utils::uriFor($path));
        } catch (\Throwable $exception) {
            throw new DisplayException('The extension repository contains an invalid archive URL.', $exception);
        }
    }

    private function normalizeChecksum(string $checksum): string
    {
        $checksum = strtolower(trim($checksum));

        return Str::startsWith($checksum, 'sha256:') ? substr($checksum, 7) : $checksum;
    }

    public function assertRepositoryEnabled(int $repositoryId): void
    {
        if (!ExtensionRepository::query()->whereKey($repositoryId)->where('enabled', true)->exists()) {
            throw new DisplayException('The selected extension repository is disabled or no longer exists.');
        }
    }
}
