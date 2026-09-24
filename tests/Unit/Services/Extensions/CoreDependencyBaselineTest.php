<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;

/**
 * resources/extensions/core-dependencies.json is what stops the panel from
 * suggesting an operator remove a package core itself needs, so it must list
 * exactly what this repository's manifests declare. It is a snapshot because
 * an operator's own frontend/package.json and composer.json gain extension
 * requirements over time; CI sees them untouched, which is where it is checked.
 */
class CoreDependencyBaselineTest extends TestCase
{
    public function testTheBaselineMatchesCoresManifests(): void
    {
        exec(sprintf('%s %s --check 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(base_path('scripts/extension-core-dependencies.php'))), $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
    }
}
