<?php

namespace Everest\Console\Commands\Extensions;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionBuildInputsService;

/**
 * The root `pnpm build` runs this first (its `prebuild` hook), so a build an
 * operator starts by hand gets the same extension allowlist and frontend
 * integrity gate as one an install starts.
 */
class PrepareExtensionBuildCommand extends Command
{
    protected $signature = 'p:ext:prepare-build';

    protected $description = 'Verify installed extensions\' frontend files and write the list of packages the next frontend build may include.';

    public function handle(ExtensionBuildInputsService $buildInputs): int
    {
        try {
            $ids = $buildInputs->prepare();
        } catch (QueryException $exception) {
            // No database here (a fresh checkout, a CI box). Leave any
            // existing list alone rather than widen the build; with none at
            // all the build includes every package directory, as in dev.
            $this->warn('Extension build inputs not updated: the database is unavailable (' . $exception->getMessage() . ').');

            return self::SUCCESS;
        } catch (DisplayException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($ids === []
            ? 'No extension packages will be included in the build.'
            : 'Extension packages included in the build: ' . implode(', ', $ids) . '.');

        return self::SUCCESS;
    }
}
