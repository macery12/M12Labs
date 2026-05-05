<?php

namespace Everest\Console\Commands\Extensions;

use Everest\Console\Commands\Extensions\Concerns\HandlesExtensionPackages;
use Everest\Console\Commands\Extensions\Concerns\InteractsWithExtensionRepositories;
use Everest\Services\Extensions\ExtensionFilesystemOwnershipService;
use Everest\Services\Extensions\ExtensionPackageUninstallService;
use Illuminate\Console\Command;

class UninstallExtensionCommand extends Command
{
    use HandlesExtensionPackages, InteractsWithExtensionRepositories;

    protected $signature = 'p:extensions:uninstall
                            {extensionId : Installed extension id to remove}
                            {--force : Skip the confirmation prompt}
                            {--debug : Show detailed uninstall diagnostics}';

    protected $description = 'Uninstall an M12Labs extension package from the panel filesystem.';

    public function __construct(
        private ExtensionPackageUninstallService $uninstallService,
        private ExtensionFilesystemOwnershipService $ownershipService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $extensionId = trim((string) $this->argument('extensionId'));

        if (!$this->option('force') && !$this->confirm(sprintf('Uninstall extension "%s"?', $extensionId))) {
            $this->components->warn('Cancelled.');

            return self::SUCCESS;
        }

        try {
            $this->uninstallService->uninstall($extensionId);
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            if ($this->isDebug()) {
                $this->renderDebugException($exception);
            }

            return self::FAILURE;
        } finally {
            $ownershipReport = $this->ownershipService->repairStandardPaths($extensionId);
            if ($ownershipReport !== [] && ($this->isDebug() || $this->ownershipService->isRunningAsRoot())) {
                $this->renderOwnershipReport($ownershipReport);
            }
        }

        $this->components->info(sprintf('Uninstalled %s.', $extensionId));

        return self::SUCCESS;
    }
}
