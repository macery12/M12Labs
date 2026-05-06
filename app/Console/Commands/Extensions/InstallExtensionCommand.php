<?php

namespace Everest\Console\Commands\Extensions;

use Everest\Console\Commands\Extensions\Concerns\HandlesExtensionPackages;
use Everest\Console\Commands\Extensions\Concerns\InteractsWithExtensionRepositories;
use Everest\Models\ExtensionRepository;
use Everest\Services\Extensions\ExtensionFilesystemOwnershipService;
use Everest\Services\Extensions\ExtensionPackageInstallService;
use Everest\Services\Extensions\ExtensionSecurityScanner;
use Illuminate\Console\Command;

class InstallExtensionCommand extends Command
{
    use HandlesExtensionPackages, InteractsWithExtensionRepositories;

    protected $signature = 'p:extensions:install
                            {source? : Extension id from a configured repository, or a local package file path}
                            {--path= : Explicit path to a local .M12LabsExtension file}
                            {--repository= : Repository slug or numeric id for repository installs}
                            {--release= : Specific repository version to install}
                            {--file : Prefer local package-file install mode}
                            {--label= : Stored source label for manual file installs}
                            {--yes : Skip interactive prompts when possible}
                            {--skip-scan : Skip the security scan for this install (use with caution)}
                            {--debug : Show detailed install diagnostics}';

    protected $description = 'Install an M12Labs extension from a repository entry or a local package file.';

    public function __construct(
        private ExtensionPackageInstallService $installService,
        private ExtensionFilesystemOwnershipService $ownershipService,
        private ExtensionSecurityScanner $scanner
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $source = trim((string) ($this->argument('source') ?? ''));
        $resolution = null;

        try {
            $resolution = $this->resolveResolution($source, 'install');

            if ($this->isDebug()) {
                $this->renderDebugResolution($resolution);
            }

            if ($resolution['mode'] === 'file') {
                // Run security scan before installation unless explicitly skipped.
                // The scan runs interactively here so we can prompt on warnings.
                // We then tell the service to skip its own scan to avoid double-scanning.
                if (!$this->option('skip-scan')) {
                    $scanPassed = $this->runSecurityScan($resolution['archivePath']);
                    if (!$scanPassed) {
                        return self::FAILURE;
                    }
                }

                $package = $this->installService->installFromArchive(
                    $resolution['archivePath'],
                    $resolution['label'],
                    skipScan: true, // interactive scan already ran above
                );
            } else {
                /** @var ExtensionRepository $repository */
                $repository = $resolution['repository'];
                if (!$this->option('skip-scan')) {
                    $this->components->info('Security scan will run automatically after the archive is downloaded.');
                }
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
     * Run the security scanner on the given archive path.
     * Returns true if install should proceed, false if it should be aborted.
     */
    private function runSecurityScan(string $archivePath): bool
    {
        $this->components->info('Running security scan…');

        try {
            $result = $this->scanner->scan($archivePath);
        } catch (\Throwable $e) {
            $this->components->warn('Security scan could not be completed: ' . $e->getMessage());
            if ($this->option('yes')) {
                return true;
            }

            return (bool) $this->confirm('Scan failed — proceed with installation anyway?', false);
        }

        $summary = $result->toArray()['summary'];
        $this->components->twoColumnDetail('Scan outcome', strtoupper($result->outcome));
        $this->components->twoColumnDetail('High-severity findings', (string) $summary['high']);
        $this->components->twoColumnDetail('Warnings', (string) $summary['warnings']);

        if ($result->isBlocked()) {
            $this->renderScanFindings($result->phpFindings, $result->jsFindings, $result->semgrepFindings);
            $this->components->error('Installation BLOCKED — high-severity security findings detected.');

            return false;
        }

        if ($result->hasSevereFindings()) {
            $this->renderScanFindings($result->phpFindings, $result->jsFindings, $result->semgrepFindings);
            $this->components->warn('Security warnings were found in this extension.');

            if ($this->option('yes')) {
                return true;
            }

            return (bool) $this->confirm('Proceed with installation despite warnings?', false);
        }

        $this->components->info('Security scan passed.');

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $phpFindings
     * @param array<int, array<string, mixed>> $jsFindings
     * @param array<int, array<string, mixed>> $semgrepFindings
     */
    private function renderScanFindings(array $phpFindings, array $jsFindings, array $semgrepFindings): void
    {
        $allFindings = array_merge($phpFindings, $jsFindings, $semgrepFindings);
        if ($allFindings === []) {
            return;
        }

        $rows = array_map(function (array $f): array {
            $sev = $f['severity'] ?? 'UNKNOWN';

            return [
                is_int($sev) ? ($sev >= 2 ? 'ERROR' : 'WARNING') : strtoupper((string) $sev),
                basename((string) ($f['file'] ?? '')),
                (string) ($f['line'] ?? 0),
                mb_strimwidth((string) ($f['message'] ?? ''), 0, 80, '…'),
            ];
        }, $allFindings);

        $this->table(['Severity', 'File', 'Line', 'Message'], $rows);
    }
}
