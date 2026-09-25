<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionPackage;
use Everest\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\ExtensionRequirementService;

class ExtensionRequirementUnusedPackagesTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function testOnlyPackagesNoLongerDeclaredByAnotherInstalledExtensionAreSuggested(): void
    {
        $removed = $this->package('removed_extension', [
            'npmPackages' => [
                'react-markdown' => '^10.1',
                'remark-gfm' => '^4.0',
            ],
            'composerPackages' => [
                'guzzlehttp/guzzle' => '^8.1',
                'league/commonmark' => '^2.6',
            ],
        ]);
        $this->package('remaining_extension', [
            'npmPackages' => ['react-markdown' => '^10'],
            'composerPackages' => ['guzzlehttp/guzzle' => '^8'],
        ]);

        $guidance = app(ExtensionRequirementService::class)->possiblyUnusedPackages([$removed]);

        $this->assertSame(['remark-gfm'], $guidance['npmPackages']);
        $this->assertSame(['league/commonmark'], $guidance['composerPackages']);
        $this->assertSame("pnpm --filter ./frontend remove 'remark-gfm'", $guidance['commands']['npm']);
        $this->assertSame("composer remove 'league/commonmark'", $guidance['commands']['composer']);
    }

    /**
     * By the time an extension's requirement is satisfied it is a direct
     * dependency in core's own manifests, so "no other extension asks for it"
     * does not mean "nothing uses it". A package core itself depends on is
     * never suggested for removal: `pnpm remove react` would take the panel
     * down with the extension.
     */
    public function testCoresOwnDependenciesAreNeverSuggested(): void
    {
        $removed = $this->package('removed_extension', [
            'npmPackages' => ['react' => '^19', 'left-pad' => '^1.3'],
            'composerPackages' => ['laravel/framework' => '^11', 'vendor/only-for-this' => '^1'],
        ]);

        $guidance = app(ExtensionRequirementService::class)->possiblyUnusedPackages([$removed]);

        $this->assertSame(['left-pad'], $guidance['npmPackages']);
        $this->assertSame(['vendor/only-for-this'], $guidance['composerPackages']);
    }

    /**
     * Without the baseline there is no telling core's packages from an
     * extension's, and the safe answer to "what can I remove" is nothing.
     */
    public function testNothingIsSuggestedWhenCoresBaselineIsUnreadable(): void
    {
        $removed = $this->package('removed_extension', ['npmPackages' => ['left-pad' => '^1.3']]);

        $original = base_path();
        $empty = sys_get_temp_dir() . '/m12labs-no-baseline-' . bin2hex(random_bytes(6));
        mkdir($empty);
        $this->app->setBasePath($empty);

        try {
            $guidance = app(ExtensionRequirementService::class)->possiblyUnusedPackages([$removed]);
        } finally {
            $this->app->setBasePath($original);
            rmdir($empty);
        }

        $this->assertSame([], $guidance['npmPackages']);
        $this->assertSame([], $guidance['commands']);
    }

    private function package(string $id, array $requirements): ExtensionPackage
    {
        return ExtensionPackage::query()->create([
            'extension_id' => $id,
            'package_id' => $id,
            'name' => $id,
            'installed_version' => '1.0.0',
            'manifest' => ['requirements' => $requirements],
            'manifest_version' => 3,
            'signature_state' => 'verified',
            'state' => 'installed_disabled',
        ]);
    }
}
