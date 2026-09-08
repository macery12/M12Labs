<?php

namespace Everest\Console\Commands\Extensions;

use Illuminate\Console\Command;
use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Everest\Services\Extensions\ExtensionRuntimeGate;
use Everest\Services\Extensions\ExtensionPackageUninstallService;

/**
 * Core-owned repair command for packages that cannot run on this panel.
 *
 * Every other extension entry point refuses to touch a package whose lifecycle
 * state forbids execution, which would otherwise leave an operator with a
 * quarantined package and no way to remove it. This command is deliberately the
 * single exception: it inspects and removes such packages without loading any
 * of their code (no route file, schedule file, or command from the package is
 * ever require()'d — removal is driven entirely by the install journal).
 */
class RepairExtensionCommand extends Command
{
    protected $signature = 'p:ext:repair
                            {--uninstall= : Extension id to force-remove, including one quarantined as unsupported}
                            {--drop-data : With --uninstall, also roll back the extension\'s migrations, DROPPING its tables (unrecoverable)}
                            {--force : Skip the confirmation prompts}
                            {--debug : Show detailed diagnostics}';

    protected $description = 'Inspect and repair extension packages that cannot run on this panel.';

    public function __construct(private ExtensionPackageUninstallService $uninstallService)
    {
        parent::__construct();
    }

    private function isDebug(): bool
    {
        return (bool) $this->option('debug');
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

    public function handle(): int
    {
        $extensionId = trim((string) $this->option('uninstall'));

        return $extensionId === '' ? $this->report() : $this->uninstall($extensionId);
    }

    /**
     * List every installed package with its lifecycle state, so an operator can
     * see what is quarantined and why before deciding to remove anything.
     */
    private function report(): int
    {
        $packages = ExtensionPackage::query()->orderBy('extension_id')->get();

        if ($packages->isEmpty()) {
            $this->components->info('No extension packages are installed.');

            return self::SUCCESS;
        }

        $enabled = ExtensionConfig::query()->where('enabled', true)->pluck('extension_id')->all();
        $runtime = ExtensionRuntimeGate::enabledExtensionIds();

        $this->table(
            ['Extension', 'Version', 'Manifest', 'State', 'Flag', 'Loading', 'Reason'],
            $packages->map(fn (ExtensionPackage $package): array => [
                $package->extension_id,
                $package->installed_version,
                'v' . $package->manifest_version,
                $package->state,
                in_array($package->extension_id, $enabled, true) ? 'enabled' : 'disabled',
                in_array($package->extension_id, $runtime, true) ? 'yes' : 'no',
                $package->state_reason ? \Illuminate\Support\Str::limit($package->state_reason, 60) : '',
            ])->all()
        );

        $quarantined = $packages->reject(fn (ExtensionPackage $package): bool => in_array($package->state, ['enabled', 'installed_disabled'], true));

        if ($quarantined->isNotEmpty()) {
            $this->newLine();
            $this->components->warn(sprintf(
                '%d package(s) cannot run on this panel. Remove one with: php artisan p:ext:repair --uninstall=<id>',
                $quarantined->count()
            ));
        }

        return self::SUCCESS;
    }

    private function uninstall(string $extensionId): int
    {
        $package = ExtensionPackage::query()->where('extension_id', $extensionId)->first();

        if (!$package) {
            $this->components->error(sprintf('No extension package named "%s" is installed.', $extensionId));

            return self::FAILURE;
        }

        $dropData = (bool) $this->option('drop-data');

        $this->components->info(sprintf(
            'Package "%s" %s (manifest v%d) is in state "%s".',
            $extensionId,
            $package->installed_version,
            $package->manifest_version,
            $package->state
        ));

        if ($package->state_reason) {
            $this->components->warn($package->state_reason);
        }

        if (!$this->option('force') && !$this->confirm(sprintf('Remove extension "%s"?', $extensionId))) {
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
                sprintf('cli-repair:%s', get_current_user() ?: 'unknown')
            );
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            if ($this->isDebug()) {
                $this->renderDebugException($exception);
            }

            return self::FAILURE;
        }

        ExtensionRuntimeGate::flush();

        $this->components->info(sprintf('Removed extension "%s".', $extensionId));

        if (!empty($result['manualCleanup'])) {
            $this->newLine();
            $this->components->warn('This package\'s tables were preserved. To remove them manually:');
            foreach ((array) $result['manualCleanup'] as $statement) {
                $this->line('  ' . $statement);
            }
        }

        return self::SUCCESS;
    }
}
