<?php

namespace Everest\Services\Extensions;

use Composer\Semver\Semver;
use Symfony\Component\Yaml\Yaml;
use Everest\Models\ExtensionPackage;
use Everest\Exceptions\DisplayException;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\Yaml\Exception\ParseException;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Everest\Services\Extensions\Manifest\ExtensionManifest;
use Everest\Exceptions\Service\Extension\PackageRequirementsNotSatisfiedException;

/**
 * Verifies declared runtime dependencies before files or migrations are applied.
 */
class ExtensionRequirementService
{
    /** @var array<string, class-string> */
    private const PANEL_SERVICES = [
        'http-client' => HttpClientFactory::class,
        'queue' => QueueFactory::class,
        'schedule' => ExtensionScheduleService::class,
        'secrets' => ExtensionSecretStore::class,
        'hooks' => ExtensionHookDispatcher::class,
    ];

    /** @var array<string, string>|null Package name => exact version from pnpm-lock.yaml. */
    private ?array $providedNpmPackages = null;

    /** @var array<string, string>|null Package name => exact version from composer.lock. */
    private ?array $providedComposerPackages = null;

    public function __construct(private Container $container)
    {
    }

    /** @throws DisplayException */
    public function assertSatisfied(ExtensionManifest $manifest): void
    {
        $platformProblems = [];

        foreach (array_unique((array) ($manifest->requirements['phpExtensions'] ?? [])) as $extension) {
            $extension = trim((string) $extension);
            if ($extension === '' || !extension_loaded($extension)) {
                $platformProblems[] = sprintf('PHP extension "%s" is not loaded', $extension === '' ? '(empty)' : $extension);
            }
        }

        foreach (array_unique((array) ($manifest->requirements['panelServices'] ?? [])) as $service) {
            $service = (string) $service;
            $abstract = self::PANEL_SERVICES[$service] ?? null;
            if ($abstract === null || !$this->canResolve($abstract)) {
                $platformProblems[] = sprintf('panel service "%s" is unavailable', $service);
            }
        }

        if ($platformProblems !== []) {
            throw new DisplayException(sprintf('Extension "%s" cannot be installed because its requirements are not satisfied: %s.', $manifest->id, implode('; ', $platformProblems)));
        }

        $npmRequirements = (array) ($manifest->requirements['npmPackages'] ?? []);
        $composerRequirements = (array) ($manifest->requirements['composerPackages'] ?? []);
        $packageProblems = array_merge(
            $npmRequirements === [] ? [] : $this->packageProblems('npm', $npmRequirements, $this->providedNpmPackages()),
            $composerRequirements === [] ? [] : $this->packageProblems('composer', $composerRequirements, $this->providedComposerPackages()),
        );

        if ($packageProblems !== []) {
            throw new PackageRequirementsNotSatisfiedException($manifest->id, $packageProblems, $this->installCommands($packageProblems));
        }
    }

    /**
     * Packages extension source can actually resolve from the frontend
     * workspace. Transitive lockfile entries are deliberately excluded: pnpm
     * does not promise them as importable dependencies, and core may change
     * that graph without a compatibility commitment.
     *
     * @return array<string, string> package name => exact locked version
     *
     * @throws DisplayException
     */
    public function providedNpmPackages(): array
    {
        if ($this->providedNpmPackages !== null) {
            return $this->providedNpmPackages;
        }

        $packagePath = base_path('frontend/package.json');
        $lockPath = base_path('pnpm-lock.yaml');

        if (!is_file($packagePath) || !is_file($lockPath)) {
            throw new DisplayException('The panel cannot verify frontend dependencies because frontend/package.json or pnpm-lock.yaml is missing.');
        }

        try {
            $packageManifest = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);
            $lockfile = Yaml::parseFile($lockPath);
        } catch (\JsonException|ParseException $exception) {
            throw new DisplayException('The panel cannot verify frontend dependencies because its package manifest or pnpm lockfile is invalid.', previous: $exception);
        }

        $declared = is_array($packageManifest) ? ($packageManifest['dependencies'] ?? null) : null;
        $locked = is_array($lockfile) ? ($lockfile['importers']['frontend']['dependencies'] ?? null) : null;
        if (!is_array($declared) || !is_array($locked)) {
            throw new DisplayException('The panel cannot verify frontend dependencies because its frontend dependency metadata is incomplete.');
        }

        $provided = [];
        foreach ($declared as $package => $specifier) {
            $lockEntry = $locked[$package] ?? null;
            if (!is_string($package) || !is_string($specifier) || !is_array($lockEntry)) {
                continue;
            }

            // Both files must describe the same direct dependency. A stale
            // lockfile is not a trustworthy compatibility contract.
            if (($lockEntry['specifier'] ?? null) !== $specifier || !is_string($lockEntry['version'] ?? null)) {
                continue;
            }

            $version = $this->lockedNpmVersion($lockEntry['version']);
            if ($version !== null) {
                $provided[$package] = $version;
            }
        }

        ksort($provided, SORT_STRING);

        return $this->providedNpmPackages = $provided;
    }

    /**
     * Direct production dependencies available to extension PHP code. A
     * transitive Composer package does not count: the panel may replace or
     * remove it without making a compatibility promise to extensions.
     *
     * @return array<string, string> package name => exact locked version
     *
     * @throws DisplayException
     */
    public function providedComposerPackages(): array
    {
        if ($this->providedComposerPackages !== null) {
            return $this->providedComposerPackages;
        }

        $manifestPath = base_path('composer.json');
        $lockPath = base_path('composer.lock');
        if (!is_file($manifestPath) || !is_file($lockPath)) {
            throw new DisplayException('The panel cannot verify backend dependencies because composer.json or composer.lock is missing.');
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $lockfile = json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DisplayException('The panel cannot verify backend dependencies because its Composer manifest or lockfile is invalid.', previous: $exception);
        }

        $declared = is_array($manifest) ? ($manifest['require'] ?? null) : null;
        $locked = is_array($lockfile) ? ($lockfile['packages'] ?? null) : null;
        if (!is_array($declared) || !is_array($locked)) {
            throw new DisplayException('The panel cannot verify backend dependencies because its Composer dependency metadata is incomplete.');
        }

        $lockedVersions = [];
        foreach ($locked as $entry) {
            if (!is_array($entry) || !is_string($entry['name'] ?? null) || !is_string($entry['version'] ?? null)) {
                continue;
            }

            $lockedVersions[strtolower($entry['name'])] = $entry['version'];
        }

        $provided = [];
        foreach ($declared as $package => $constraint) {
            $package = strtolower((string) $package);
            if (!str_contains($package, '/') || !is_string($constraint) || !isset($lockedVersions[$package])) {
                continue;
            }

            $provided[$package] = $lockedVersions[$package];
        }

        ksort($provided, SORT_STRING);

        return $this->providedComposerPackages = $provided;
    }

    /**
     * Report packages declared only by extensions that have just been removed.
     * The commands are advice only; the lifecycle system never executes them.
     *
     * @param iterable<int, ExtensionPackage> $removedPackages
     *
     * @return array{npmPackages: array<int, string>, composerPackages: array<int, string>, commands: array<string, string>}
     */
    public function possiblyUnusedPackages(iterable $removedPackages): array
    {
        $removedIds = [];
        $npm = [];
        $composer = [];

        foreach ($removedPackages as $package) {
            $removedIds[] = (string) $package->extension_id;
            $requirements = $this->manifestRequirements($package->manifest);
            $npm += array_fill_keys(array_keys($requirements['npmPackages']), true);
            $composer += array_fill_keys(array_keys($requirements['composerPackages']), true);
        }

        if ($npm === [] && $composer === []) {
            return $this->unusedPackageGuidance([], []);
        }

        $remaining = ExtensionPackage::query()
            ->when($removedIds !== [], fn ($query) => $query->whereNotIn('extension_id', array_unique($removedIds)))
            ->get(['manifest']);

        foreach ($remaining as $package) {
            $requirements = $this->manifestRequirements($package->manifest);
            foreach (array_keys($requirements['npmPackages']) as $name) {
                unset($npm[$name]);
            }
            foreach (array_keys($requirements['composerPackages']) as $name) {
                unset($composer[$name]);
            }
        }

        return $this->unusedPackageGuidance(array_keys($npm), array_keys($composer));
    }

    /**
     * @param 'npm'|'composer' $manager
     * @param array<string, string> $requirements
     * @param array<string, string> $provided
     *
     * @return array<int, array{type: 'frontend'|'backend', manager: 'npm'|'composer', package: string, required: string, installed: string|null, status: 'missing'|'incompatible'}>
     */
    private function packageProblems(string $manager, array $requirements, array $provided): array
    {
        $problems = [];

        foreach ($requirements as $package => $constraint) {
            $package = (string) $package;
            $constraint = (string) $constraint;
            $version = $provided[$package] ?? null;
            $status = $version === null ? 'missing' : null;

            if ($version !== null) {
                try {
                    $status = Semver::satisfies($version, $constraint) ? null : 'incompatible';
                } catch (\UnexpectedValueException) {
                    // The manifest parser validates constraints. Manifests
                    // hydrated through another path still fail closed here.
                    $status = 'incompatible';
                }
            }

            if ($status !== null) {
                $problems[] = [
                    'type' => $manager === 'npm' ? 'frontend' : 'backend',
                    'manager' => $manager,
                    'package' => $package,
                    'required' => $constraint,
                    'installed' => $version,
                    'status' => $status,
                ];
            }
        }

        return $problems;
    }

    /**
     * @param array<int, array{manager: string, package: string, required: string}> $problems
     *
     * @return array<string, string>
     */
    private function installCommands(array $problems): array
    {
        $npm = [];
        $composer = [];

        foreach ($problems as $problem) {
            if ($problem['manager'] === 'npm') {
                $npm[] = $this->shellArgument($problem['package'] . '@' . $problem['required']);
            } else {
                $composer[] = $this->shellArgument($problem['package'] . ':' . $problem['required']);
            }
        }

        return array_filter([
            'npm' => $npm === [] ? null : 'pnpm --filter ./frontend add ' . implode(' ', $npm),
            'composer' => $composer === [] ? null : 'composer require ' . implode(' ', $composer),
        ], 'is_string');
    }

    /**
     * @param array<int, string> $npm
     * @param array<int, string> $composer
     *
     * @return array{npmPackages: array<int, string>, composerPackages: array<int, string>, commands: array<string, string>}
     */
    private function unusedPackageGuidance(array $npm, array $composer): array
    {
        sort($npm, SORT_STRING);
        sort($composer, SORT_STRING);

        return [
            'npmPackages' => $npm,
            'composerPackages' => $composer,
            'commands' => array_filter([
                'npm' => $npm === [] ? null : 'pnpm --filter ./frontend remove ' . implode(' ', array_map($this->shellArgument(...), $npm)),
                'composer' => $composer === [] ? null : 'composer remove ' . implode(' ', array_map($this->shellArgument(...), $composer)),
            ], 'is_string'),
        ];
    }

    /** @return array{npmPackages: array<string, mixed>, composerPackages: array<string, mixed>} */
    private function manifestRequirements($manifest): array
    {
        $requirements = is_array($manifest) && is_array($manifest['requirements'] ?? null)
            ? $manifest['requirements']
            : [];

        $npm = is_array($requirements['npmPackages'] ?? null) && !array_is_list($requirements['npmPackages'])
            ? $requirements['npmPackages']
            : [];
        $composer = is_array($requirements['composerPackages'] ?? null) && !array_is_list($requirements['composerPackages'])
            ? $requirements['composerPackages']
            : [];

        return ['npmPackages' => $npm, 'composerPackages' => $composer];
    }

    private function lockedNpmVersion(string $locked): ?string
    {
        // pnpm appends peer resolutions in parentheses, for example
        // 5.102.8(react@19.2.8). Only the package's own version participates in
        // its requirement comparison.
        if (preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?)(?:\(|$)/', $locked, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function shellArgument(string $argument): string
    {
        return "'" . str_replace("'", "'\\''", $argument) . "'";
    }

    private function canResolve(string $abstract): bool
    {
        try {
            $this->container->make($abstract);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
