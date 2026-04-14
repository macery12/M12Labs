<?php

namespace Everest\Console\Commands\Extensions;

use Everest\Console\Commands\Extensions\Concerns\InteractsWithExtensionRepositories;
use Everest\Exceptions\DisplayException;
use Everest\Models\ExtensionRepository;
use Everest\Services\Extensions\ExtensionFilesystemOwnershipService;
use Everest\Services\Extensions\ExtensionPackageArtifactService;
use Everest\Services\Extensions\ExtensionPackageInstallService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallExtensionCommand extends Command
{
    use InteractsWithExtensionRepositories;

    protected $signature = 'p:extensions:install
                            {source? : Extension id from a configured repository, or a local package file path}
                            {--path= : Explicit path to a local .M12LabsExtension file}
                            {--repository= : Repository slug or numeric id for repository installs}
                            {--release= : Specific repository version to install}
                            {--file : Prefer local package-file install mode}
                            {--label= : Stored source label for manual file installs}
                            {--yes : Skip interactive prompts when possible}
                            {--debug : Show detailed install diagnostics}';

    protected $description = 'Install an M12Labs extension from a repository entry or a local package file.';

    public function __construct(
        private ExtensionPackageInstallService $installService,
        private ExtensionPackageArtifactService $artifactService,
        private ExtensionFilesystemOwnershipService $ownershipService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $source = trim((string) ($this->argument('source') ?? ''));
        $resolution = null;

        try {
            $resolution = $this->resolveInstallResolution($source);

            if ($this->isDebug()) {
                $this->renderDebugResolution($resolution);
            }

            if ($resolution['mode'] === 'file') {
                $package = $this->installService->installFromArchive(
                    $resolution['archivePath'],
                    $resolution['label'],
                );
            } else {
                /** @var ExtensionRepository $repository */
                $repository = $resolution['repository'];
                $package = $this->installService->install(
                    $resolution['extensionId'],
                    $repository->id,
                    $resolution['release'],
                );
            }
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            if ($this->isDebug()) {
                $this->renderDebugException($exception);
            }

            return self::FAILURE;
        } finally {
            $ownershipReport = $this->ownershipService->repairStandardPaths($resolution['extensionId'] ?? null);
            if ($ownershipReport !== [] && ($this->isDebug() || $this->ownershipService->isRunningAsRoot())) {
                $this->renderOwnershipReport($ownershipReport);
            }
        }

        $this->components->info(sprintf('Installed %s (%s).', $package->extension_id, $package->installed_version));
        $this->table(['Field', 'Value'], [
            ['Extension', $package->extension_id],
            ['Version', $package->installed_version],
            ['Source', $package->source_repository_name ?? 'Repository'],
            ['Archive', $package->source_archive_url ?? 'n/a'],
            ['Files', (string) $package->files->count()],
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveInstallResolution(string $source): array
    {
        $cwd = getcwd() ?: base_path();
        $discoveredArtifacts = $this->artifactService->discoverArchives($cwd);
        $validArtifacts = array_values(array_filter($discoveredArtifacts, fn (array $artifact): bool => !isset($artifact['error'])));
        $explicitPath = $this->option('path') ? trim((string) $this->option('path')) : null;

        if ($explicitPath !== null && $explicitPath !== '') {
            return $this->createFileResolution($explicitPath, $cwd, $discoveredArtifacts, $source);
        }

        if ($this->option('file')) {
            return $this->resolveFileModeSelection($source, $cwd, $discoveredArtifacts, $validArtifacts);
        }

        if ($source !== '' && $this->artifactService->looksLikeArchiveReference($source, $cwd)) {
            return $this->createFileResolution($source, $cwd, $discoveredArtifacts, $source);
        }

        if ($source !== '') {
            $matchingLocal = array_values(array_filter(
                $validArtifacts,
                fn (array $artifact): bool => ($artifact['extensionId'] ?? null) === $source
            ));

            if (count($matchingLocal) === 1) {
                if ($this->option('yes') || $this->confirm(sprintf(
                    'Found local package %s (%s) in %s. Install that file instead of using the repository?',
                    $matchingLocal[0]['name'],
                    $matchingLocal[0]['version'],
                    $cwd,
                ), true)) {
                    return $this->createFileResolution($matchingLocal[0]['archivePath'], $cwd, $discoveredArtifacts, $source);
                }
            } elseif ($validArtifacts !== [] && !$this->option('yes')) {
                $choice = $this->choice(
                    sprintf('Found %d local extension package file(s) in %s while you asked for "%s". What do you want to do?', count($validArtifacts), $cwd, $source),
                    ['Use repository install', 'Select a discovered package', 'Enter a path', 'Cancel'],
                    'Use repository install'
                );

                if ($choice === 'Select a discovered package') {
                    return $this->selectDiscoveredArtifact($validArtifacts, $cwd, $discoveredArtifacts, $source);
                }

                if ($choice === 'Enter a path') {
                    $path = trim((string) $this->ask('Enter the path to the .M12LabsExtension file'));

                    return $this->createFileResolution($path, $cwd, $discoveredArtifacts, $source);
                }

                if ($choice === 'Cancel') {
                    throw new DisplayException('Installation cancelled.');
                }
            }
        }

        if ($source === '') {
            if ($validArtifacts === []) {
                throw new DisplayException('No extension id or local package file was provided. Run this command in a directory containing a .M12LabsExtension file, pass --path, or provide an extension id.');
            }

            if (count($validArtifacts) === 1) {
                if ($this->option('yes') || $this->confirm(sprintf(
                    'Install the local package %s (%s) found in %s?',
                    $validArtifacts[0]['name'],
                    $validArtifacts[0]['version'],
                    $cwd,
                ), true)) {
                    return $this->createFileResolution($validArtifacts[0]['archivePath'], $cwd, $discoveredArtifacts, $validArtifacts[0]['extensionId']);
                }

                throw new DisplayException('Installation cancelled.');
            }

            if ($this->option('yes')) {
                throw new DisplayException('Multiple local extension packages were found. Re-run without --yes to select one, or pass --path explicitly.');
            }

            return $this->selectDiscoveredArtifact($validArtifacts, $cwd, $discoveredArtifacts, null);
        }

        $repository = $this->resolveRepository($this->option('repository'));

        return [
            'mode' => 'repository',
            'extensionId' => $source,
            'repository' => $repository,
            'release' => $this->option('release') ? trim((string) $this->option('release')) : null,
            'discoveredArtifacts' => $discoveredArtifacts,
            'cwd' => $cwd,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $discoveredArtifacts
     * @param array<int, array<string, mixed>> $validArtifacts
     * @return array<string, mixed>
     */
    private function resolveFileModeSelection(string $source, string $cwd, array $discoveredArtifacts, array $validArtifacts): array
    {
        if ($source !== '') {
            return $this->createFileResolution($source, $cwd, $discoveredArtifacts, $source);
        }

        if ($validArtifacts === []) {
            throw new DisplayException('No local .M12LabsExtension files were found in the current directory. Pass a path or omit --file to install from a repository.');
        }

        if (count($validArtifacts) === 1) {
            return $this->createFileResolution($validArtifacts[0]['archivePath'], $cwd, $discoveredArtifacts, $validArtifacts[0]['extensionId']);
        }

        if ($this->option('yes')) {
            throw new DisplayException('Multiple local extension packages were found. Pass --path or re-run without --yes to choose one interactively.');
        }

        return $this->selectDiscoveredArtifact($validArtifacts, $cwd, $discoveredArtifacts, null);
    }

    /**
     * @param array<int, array<string, mixed>> $validArtifacts
     * @param array<int, array<string, mixed>> $discoveredArtifacts
     * @return array<string, mixed>
     */
    private function selectDiscoveredArtifact(array $validArtifacts, string $cwd, array $discoveredArtifacts, ?string $requestedSource): array
    {
        $choices = [];
        foreach ($validArtifacts as $index => $artifact) {
            $choices[] = sprintf('%d. %s (%s) [%s]', $index + 1, $artifact['name'], $artifact['version'], $artifact['archiveName']);
        }

        $choices[] = 'Enter a path';
        $choices[] = 'Cancel';

        $choice = $this->choice(
            sprintf('Found %d local extension package file(s) in %s. Select one to install.', count($validArtifacts), $cwd),
            $choices,
            $choices[0]
        );

        if ($choice === 'Enter a path') {
            $path = trim((string) $this->ask('Enter the path to the .M12LabsExtension file'));

            return $this->createFileResolution($path, $cwd, $discoveredArtifacts, $requestedSource);
        }

        if ($choice === 'Cancel') {
            throw new DisplayException('Installation cancelled.');
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
     * @return array<string, mixed>
     */
    private function createFileResolution(string $path, string $cwd, array $discoveredArtifacts, ?string $requestedSource): array
    {
        $artifact = $this->artifactService->inspectArchive($path, $cwd);

        return [
            'mode' => 'file',
            'extensionId' => $artifact['extensionId'],
            'archivePath' => $artifact['archivePath'],
            'label' => $this->option('label') ? trim((string) $this->option('label')) : sprintf('Manual package file (%s)', $artifact['archiveName']),
            'artifact' => $artifact,
            'requestedSource' => $requestedSource,
            'discoveredArtifacts' => $discoveredArtifacts,
            'cwd' => $cwd,
        ];
    }

    /**
     * @param array<string, mixed> $resolution
     */
    private function renderDebugResolution(array $resolution): void
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

    private function renderDebugException(\Throwable $exception): void
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
    private function renderOwnershipReport(array $report): void
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

    protected function isDebug(): bool
    {
        return (bool) $this->option('debug');
    }
}