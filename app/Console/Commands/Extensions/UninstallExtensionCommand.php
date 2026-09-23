<?php

namespace Everest\Console\Commands\Extensions;

use Illuminate\Console\Command;
use Everest\Services\Extensions\ExtensionPackageUninstallService;
use Everest\Services\Extensions\ExtensionFilesystemOwnershipService;
use Everest\Console\Commands\Extensions\Concerns\HandlesExtensionPackages;
use Everest\Console\Commands\Extensions\Concerns\InteractsWithExtensionRepositories;

class UninstallExtensionCommand extends Command
{
    use HandlesExtensionPackages;
    use InteractsWithExtensionRepositories;

    protected $signature = 'p:extensions:uninstall
                            {extensionId : Installed extension id to remove}
                            {--force : Skip the confirmation prompts}
                            {--allow-modified : Proceed even though tracked files were changed after installation, discarding those changes}
                            {--drop-data : Also roll back the extension\'s migrations, DROPPING its database tables (unrecoverable)}
                            {--debug : Show detailed uninstall diagnostics}';

    protected $description = 'Uninstall an M12Labs extension package from the panel filesystem. Database tables are preserved unless --drop-data is given.';

    public function __construct(
        private ExtensionPackageUninstallService $uninstallService,
        private ExtensionFilesystemOwnershipService $ownershipService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $extensionId = trim((string) $this->argument('extensionId'));
        $dropData = (bool) $this->option('drop-data');

        if (!$this->option('force') && !$this->confirm(sprintf('Uninstall extension "%s"?', $extensionId))) {
            $this->components->warn('Cancelled.');

            return self::SUCCESS;
        }

        if ($dropData && !$this->option('force')) {
            $this->components->warn('--drop-data will roll back this extension\'s migrations and DROP its database tables. This cannot be undone.');
            $typed = (string) $this->ask(sprintf('Type the extension id ("%s") to confirm dropping its data', $extensionId));

            if (trim($typed) !== $extensionId) {
                $this->components->error('Confirmation did not match the extension id. Nothing was uninstalled.');

                return self::FAILURE;
            }
        }

        try {
            $result = $this->uninstallService->uninstall(
                $extensionId,
                $dropData,
                sprintf('cli:%s', get_current_user() ?: 'unknown'),
                (bool) $this->option('allow-modified')
            );
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
            $ownershipReport = $this->ownershipService->repairStandardPaths($extensionId);
            if ($ownershipReport !== [] && ($this->isDebug() || $this->ownershipService->isRunningAsRoot())) {
                $this->renderOwnershipReport($ownershipReport);
            }
        }

        $this->components->info(sprintf('Uninstalled %s.', $extensionId));
        $this->reconcileHorizon();

        if ($result['dataDropped']) {
            $this->components->info(sprintf('Database tables were dropped. Audit log: %s', $result['migrationLog']));
        } elseif ($result['preservedTables'] !== []) {
            $this->components->warn(sprintf(
                'Database tables were preserved (%s). Reinstalling the extension will reattach to this data.',
                implode(', ', $result['preservedTables'])
            ));
            $this->line('To remove the data manually, run the following SQL against the panel database:');
            foreach ($result['manualCleanup'] as $statement) {
                $this->line('  ' . $statement);
            }
        }

        $unused = $result['possiblyUnusedPackages'];
        if (($unused['npmPackages'] ?? []) !== [] || ($unused['composerPackages'] ?? []) !== []) {
            $this->newLine();
            $this->components->warn('These manually managed packages are no longer declared by an installed extension. They were not removed automatically.');

            if (($unused['npmPackages'] ?? []) !== []) {
                $this->line('Frontend: ' . implode(', ', $unused['npmPackages']));
            }
            if (($unused['composerPackages'] ?? []) !== []) {
                $this->line('Backend: ' . implode(', ', $unused['composerPackages']));
            }

            foreach ($unused['commands'] ?? [] as $command) {
                $this->line('  ' . $command);
            }
        }

        return self::SUCCESS;
    }
}
