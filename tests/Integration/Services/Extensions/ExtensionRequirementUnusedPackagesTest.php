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
