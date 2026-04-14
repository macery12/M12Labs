<?php

namespace Everest\Console\Commands\Extensions;

use Everest\Services\Extensions\ExtensionFilesystemOwnershipService;
use Everest\Services\Extensions\ExtensionPackageUninstallService;
use Illuminate\Console\Command;

class UninstallExtensionCommand extends Command
{
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

            if ((bool) $this->option('debug')) {
                $this->line(sprintf('Debug: %s', $exception::class));
                $previous = $exception->getPrevious();
                while ($previous) {
                    $this->line(sprintf('Caused by: %s - %s', $previous::class, $previous->getMessage()));
                    $previous = $previous->getPrevious();
                }
            }

            return self::FAILURE;
        } finally {
            $ownershipReport = $this->ownershipService->repairStandardPaths($extensionId);
            if ($ownershipReport !== [] && ((bool) $this->option('debug') || $this->ownershipService->isRunningAsRoot())) {
                $this->components->info(sprintf(
                    'Repaired ownership for extension paths using %s:%s (%s).',
                    $ownershipReport['user'],
                    $ownershipReport['group'],
                    $ownershipReport['sourcePath']
                ));
            }
        }

        $this->components->info(sprintf('Uninstalled %s.', $extensionId));

        return self::SUCCESS;
    }
}