<?php

namespace Everest\Console\Commands\Extensions\Concerns;

use Illuminate\Support\Str;
use Everest\Models\ExtensionRepository;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionPackageArtifactService;
use Everest\Exceptions\Service\Extension\CapabilityApprovalRequiredException;

/**
 * Shared helpers for the install and update Artisan commands.
 * Provides resolution logic, debug output, and ownership reporting.
 */
trait HandlesExtensionPackages
{
    /**
     * Read an option that only some of the commands using this trait define
     * (e.g. the uninstall command has no --yes/--path/--release). Returns null
     * instead of throwing when the current command lacks the option.
     */
    private function packageOption(string $key): mixed
    {
        return $this->hasOption($key) ? $this->option($key) : null;
    }

    /**
     * Resolve how to obtain the extension package — from a repository or a local file.
     * $action should be 'install' or 'update' (used only in interactive prompt text).
     *
     * @return array<string, mixed>
     */
    protected function resolveResolution(string $source, string $action): array
    {
        /** @var ExtensionPackageArtifactService $artifactService */
        $artifactService = app(ExtensionPackageArtifactService::class);

        $cwd = getcwd() ?: base_path();
        $discoveredArtifacts = $artifactService->discoverArchives($cwd);
        $validArtifacts = array_values(array_filter($discoveredArtifacts, fn (array $a): bool => !isset($a['error'])));
        $explicitPath = $this->packageOption('path') ? trim((string) $this->packageOption('path')) : null;

        if ($explicitPath !== null && $explicitPath !== '') {
            return $this->createFileResolution($explicitPath, $cwd, $discoveredArtifacts, $source);
        }

        if ($this->packageOption('file')) {
            return $this->resolveFileModeSelection($source, $cwd, $discoveredArtifacts, $validArtifacts, $action);
        }

        if ($source !== '' && $artifactService->looksLikeArchiveReference($source, $cwd)) {
            return $this->createFileResolution($source, $cwd, $discoveredArtifacts, $source);
        }

        if ($source !== '') {
            $matchingLocal = array_values(array_filter(
                $validArtifacts,
                fn (array $a): bool => ($a['extensionId'] ?? null) === $source
            ));

            if (count($matchingLocal) === 1) {
                if ($this->packageOption('yes') || $this->confirm(sprintf(
                    'Found local package %s (%s) in %s. %s from that file instead of using the repository?',
                    $matchingLocal[0]['name'],
                    $matchingLocal[0]['version'],
                    $cwd,
                    ucfirst($action)
                ), true)) {
                    return $this->createFileResolution($matchingLocal[0]['archivePath'], $cwd, $discoveredArtifacts, $source);
                }
            } elseif ($validArtifacts !== [] && !$this->packageOption('yes')) {
                $choice = $this->choice(
                    sprintf('Found %d local extension package file(s) in %s while you asked for "%s". What do you want to do?', count($validArtifacts), $cwd, $source),
                    ['Use repository ' . $action, 'Select a discovered package', 'Enter a path', 'Cancel'],
                    'Use repository ' . $action
                );

                if ($choice === 'Select a discovered package') {
                    return $this->selectDiscoveredArtifact($validArtifacts, $cwd, $discoveredArtifacts, $source, $action);
                }

                if ($choice === 'Enter a path') {
                    $path = trim((string) $this->ask('Enter the path to the .M12LabsExtension file'));

                    return $this->createFileResolution($path, $cwd, $discoveredArtifacts, $source);
                }

                if ($choice === 'Cancel') {
                    throw new DisplayException(ucfirst($action) . ' cancelled.');
                }
            }
        }

        if ($source === '') {
            if ($validArtifacts === []) {
                throw new DisplayException('No extension id or local package file was provided. Run this command in a directory containing a .M12LabsExtension file, pass --path, or provide an extension id.');
            }

            if (count($validArtifacts) === 1) {
                if ($this->packageOption('yes') || $this->confirm(sprintf(
                    ucfirst($action) . ' from the local package %s (%s) found in %s?',
                    $validArtifacts[0]['name'],
                    $validArtifacts[0]['version'],
                    $cwd,
                ), true)) {
                    return $this->createFileResolution($validArtifacts[0]['archivePath'], $cwd, $discoveredArtifacts, $validArtifacts[0]['extensionId']);
                }

                throw new DisplayException(ucfirst($action) . ' cancelled.');
            }

            if ($this->packageOption('yes')) {
                throw new DisplayException(sprintf('Multiple local extension packages were found. Re-run without --yes to select one, or pass --path explicitly.'));
            }

            return $this->selectDiscoveredArtifact($validArtifacts, $cwd, $discoveredArtifacts, null, $action);
        }

        $repository = $this->resolveRepository($this->packageOption('repository'));

        return [
            'mode'                => 'repository',
            'extensionId'         => $source,
            'repository'          => $repository,
            'release'             => $this->packageOption('release') ? trim((string) $this->packageOption('release')) : null,
            'discoveredArtifacts' => $discoveredArtifacts,
            'cwd'                 => $cwd,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $discoveredArtifacts
     * @param array<int, array<string, mixed>> $validArtifacts
     *
     * @return array<string, mixed>
     */
    private function resolveFileModeSelection(string $source, string $cwd, array $discoveredArtifacts, array $validArtifacts, string $action): array
    {
        if ($source !== '') {
            return $this->createFileResolution($source, $cwd, $discoveredArtifacts, $source);
        }

        if ($validArtifacts === []) {
            throw new DisplayException(sprintf('No local .M12LabsExtension files were found in the current directory. Pass a path or omit --file to %s from a repository.', $action));
        }

        if (count($validArtifacts) === 1) {
            return $this->createFileResolution($validArtifacts[0]['archivePath'], $cwd, $discoveredArtifacts, $validArtifacts[0]['extensionId']);
        }

        if ($this->packageOption('yes')) {
            throw new DisplayException('Multiple local extension packages were found. Pass --path or re-run without --yes to choose one interactively.');
        }

        return $this->selectDiscoveredArtifact($validArtifacts, $cwd, $discoveredArtifacts, null, $action);
    }

    /**
     * @param array<int, array<string, mixed>> $validArtifacts
     * @param array<int, array<string, mixed>> $discoveredArtifacts
     *
     * @return array<string, mixed>
     */
    private function selectDiscoveredArtifact(array $validArtifacts, string $cwd, array $discoveredArtifacts, ?string $requestedSource, string $action): array
    {
        $choices = [];
        foreach ($validArtifacts as $index => $artifact) {
            $choices[] = sprintf('%d. %s (%s) [%s]', $index + 1, $artifact['name'], $artifact['version'], $artifact['archiveName']);
        }

        $choices[] = 'Enter a path';
        $choices[] = 'Cancel';

        $choice = $this->choice(
            sprintf('Found %d local extension package file(s) in %s. Select one to %s from.', count($validArtifacts), $cwd, $action),
            $choices,
            $choices[0]
        );

        if ($choice === 'Enter a path') {
            $path = trim((string) $this->ask('Enter the path to the .M12LabsExtension file'));

            return $this->createFileResolution($path, $cwd, $discoveredArtifacts, $requestedSource);
        }

        if ($choice === 'Cancel') {
            throw new DisplayException(ucfirst($action) . ' cancelled.');
        }

        $selectedIndex = max(0, ((int) Str::before($choice, '.')) - 1);
        $selectedArtifact = $validArtifacts[$selectedIndex] ?? null;
        if (!$selectedArtifact) {
            throw new DisplayException('Unable to resolve the selected local package.');
        }

        return $this->createFileResolution($selectedArtifact['archivePath'], $cwd, $discoveredArtifacts, $requestedSource ?: $selectedArtifact['extensionId']);
    }

    /**
     * @param array<int, array<string, mixed>> $discoveredArtifacts
     *
     * @return array<string, mixed>
     */
    private function createFileResolution(string $path, string $cwd, array $discoveredArtifacts, ?string $requestedSource): array
    {
        /** @var ExtensionPackageArtifactService $artifactService */
        $artifactService = app(ExtensionPackageArtifactService::class);
        $artifact = $artifactService->inspectArchive($path, $cwd);

        return [
            'mode'                => 'file',
            'extensionId'         => $artifact['extensionId'],
            'archivePath'         => $artifact['archivePath'],
            'label'               => $this->packageOption('label') ? trim((string) $this->packageOption('label')) : sprintf('Manual package file (%s)', $artifact['archiveName']),
            'artifact'            => $artifact,
            'requestedSource'     => $requestedSource,
            'discoveredArtifacts' => $discoveredArtifacts,
            'cwd'                 => $cwd,
        ];
    }

    /**
     * @param array<string, mixed> $resolution
     */
    protected function renderDebugResolution(array $resolution): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('Mode', (string) $resolution['mode']);
        $this->components->twoColumnDetail('Working directory', (string) ($resolution['cwd'] ?? base_path()));

        if (!empty($resolution['discoveredArtifacts'])) {
            $rows = [];
            foreach ($resolution['discoveredArtifacts'] as $artifact) {
                $rows[] = [
                    $artifact['extensionId'] ?? 'invalid',
                    $artifact['version'] ?? '-',
                    $artifact['archiveName'] ?? basename((string) ($artifact['archivePath'] ?? 'unknown')),
                    $artifact['error'] ?? 'ok',
                ];
            }

            $this->table(['Extension', 'Version', 'Artifact', 'Status'], $rows);
        }

        if ($resolution['mode'] === 'file' && isset($resolution['artifact'])) {
            $artifact = $resolution['artifact'];
            $this->table(['Field', 'Value'], [
                ['Extension', $artifact['extensionId']],
                ['Version', $artifact['version']],
                ['Name', $artifact['name']],
                ['Archive', $artifact['archivePath']],
                ['Files', (string) $artifact['fileCount']],
            ]);

            return;
        }

        /** @var ExtensionRepository $repository */
        $repository = $resolution['repository'];
        $this->table(['Field', 'Value'], [
            ['Extension', $resolution['extensionId']],
            ['Repository', $repository->name],
            ['Repository slug', $repository->slug],
            ['Release', $resolution['release'] ?? 'latest'],
        ]);
    }

    protected function renderDebugException(\Throwable $exception): void
    {
        $this->newLine();
        $this->line(sprintf('Debug: %s', $exception::class));

        $previous = $exception->getPrevious();
        while ($previous) {
            $this->line(sprintf('Caused by: %s - %s', $previous::class, $previous->getMessage()));
            $previous = $previous->getPrevious();
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    protected function renderOwnershipReport(array $report): void
    {
        if ($report === [] || empty($report['paths'])) {
            return;
        }

        $this->newLine();
        $this->components->info(sprintf(
            'Repaired ownership for extension paths using %s:%s (%s).',
            $report['user'],
            $report['group'],
            $report['sourcePath']
        ));

        if ($this->isDebug()) {
            $this->table(['Path'], array_map(fn (string $path) => [$path], $report['paths']));
        }
    }

    /**
     * Run an install or update, prompting for capability approval if the
     * package asks for privileges the panel has not consented to.
     *
     * The API answers a capability escalation with 409 plus the diff and its
     * hash, and the client re-submits carrying that hash. The CLI had no
     * equivalent, which meant no package declaring routes, permissions, hooks,
     * queues, secrets, commands, migrations or a schedule could be installed or
     * updated from the command line at all.
     *
     * The hash is what makes consent specific: it covers the exact capability
     * set that was displayed, so a package that changes between the prompt and
     * the retry fails rather than inheriting approval it was not given.
     *
     * @template T
     *
     * @param callable(?string): T $operation receives the approved hash, or null on the first attempt
     *
     * @return T
     */
    protected function withCapabilityApproval(callable $operation): mixed
    {
        try {
            return $operation(null);
        } catch (CapabilityApprovalRequiredException $exception) {
            $diff = $exception->diff;

            $this->newLine();
            $this->components->warn(sprintf('"%s" requests privileges this panel has not approved:', $exception->extensionId));
            foreach ($diff->escalations as $capability) {
                $this->line('  + ' . $capability);
            }

            if ($diff->removed !== []) {
                $this->newLine();
                $this->line('No longer requested:');
                foreach ($diff->removed as $capability) {
                    $this->line('  - ' . $capability);
                }
            }

            $this->newLine();

            if (!$this->packageOption('approve-capabilities') && !$this->confirm('Grant these privileges?', false)) {
                $this->components->warn('Cancelled. Nothing was changed.');

                throw $exception;
            }

            return $operation($diff->hash);
        }
    }

    protected function isDebug(): bool
    {
        return (bool) $this->option('debug');
    }

    /**
     * Put Horizon's supervisors right after a lifecycle change commits.
     *
     * The change's own frontend build restarted Horizon before the package was
     * committed, so a lane the change made necessary is not staffed yet. The
     * scheduler would notice within a couple of minutes; a command the operator
     * is watching should not leave that gap.
     */
    protected function reconcileHorizon(): void
    {
        try {
            $missing = app(\Everest\Services\Queue\HorizonProvisioningReconciler::class)->reconcileNow();
        } catch (\Throwable $exception) {
            report($exception);

            return;
        }

        if ($missing !== null) {
            $this->components->info(sprintf('Restarting Horizon to staff %s.', $missing));
        }
    }
}
