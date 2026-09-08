<?php

namespace Everest\Console\Commands\Extensions;

use Illuminate\Console\Command;
use Everest\Models\ExtensionRepository;
use Everest\Services\Extensions\ExtensionPackageUpdateService;
use Everest\Services\Extensions\ExtensionFilesystemOwnershipService;
use Everest\Console\Commands\Extensions\Concerns\HandlesExtensionPackages;
use Everest\Console\Commands\Extensions\Concerns\InteractsWithExtensionRepositories;

class UpdateExtensionCommand extends Command
{
    use HandlesExtensionPackages;
    use InteractsWithExtensionRepositories;

    protected $signature = 'p:extensions:update
                            {source? : Extension id from a configured repository, or a local package file path}
                            {--path= : Explicit path to a local .M12LabsExtension file}
                            {--repository= : Repository slug or numeric id for repository updates}
                            {--release= : Specific repository version to update to}
                            {--file : Prefer local package-file update mode}
                            {--label= : Stored source label for manual file updates}
                            {--yes : Skip interactive prompts when possible}
                            {--allow-modified : Proceed even though tracked files were changed after installation, discarding those changes}
                            {--approve-capabilities : Grant the privileges the new release declares without prompting}
                            {--debug : Show detailed update diagnostics}';

    protected $description = 'Update an installed M12Labs extension from a repository entry or a local package file.';

    public function __construct(
        private ExtensionPackageUpdateService $updateService,
        private ExtensionFilesystemOwnershipService $ownershipService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $source = trim((string) ($this->argument('source') ?? ''));
        $resolution = ['extensionId' => null];

        try {
            $resolution = $this->resolveResolution($source, 'update');

            if ($this->isDebug()) {
                $this->renderDebugResolution($resolution);
            }

            // A v2 package upgrading to v3 has no stored capability
            // projection, so every capability the new release declares reads as
            // new and needs consent. That is the intended shape of the upgrade:
            // manifest v2 never described these privileges, so nobody ever
            // approved them.
            $package = $this->withCapabilityApproval(function (?string $approvedCapabilityHash) use ($resolution) {
                if ($resolution['mode'] === 'file') {
                    return $this->updateService->updateFromArchive(
                        $resolution['archivePath'],
                        $resolution['label'],
                        $approvedCapabilityHash,
                        (bool) $this->option('allow-modified'),
                    );
                }

                /** @var ExtensionRepository $repository */
                $repository = $resolution['repository'];

                return $this->updateService->update(
                    $resolution['extensionId'],
                    $repository->id,
                    $resolution['release'],
                    $approvedCapabilityHash,
                    (bool) $this->option('allow-modified'),
                );
            });
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            if (!$this->option('allow-modified') && str_contains($exception->getMessage(), 'modified after installation')) {
                $this->newLine();
                $this->components->warn('Re-run with --allow-modified to proceed and discard those changes.');
            }

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

        $this->components->info(sprintf('Updated %s to %s.', $package->extension_id, $package->installed_version));
        $this->table(['Field', 'Value'], [
            ['Extension', $package->extension_id],
            ['Version', $package->installed_version],
            ['Source', $package->source_repository_name ?? 'Repository'],
            ['Archive', $package->source_archive_url ?? 'n/a'],
            ['Files', (string) $package->files->count()],
        ]);

        return self::SUCCESS;
    }
}
