<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\Manifest\ExtensionManifest;
use Everest\Services\Extensions\ExtensionRequirementService;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;

class ExtensionRequirementServiceTest extends TestCase
{
    private function manifest(array $requirements): ExtensionManifest
    {
        return (new ExtensionManifestParser())->parse([
            'manifestVersion' => 3,
            'package' => ['id' => 'demo', 'version' => '1.0.0'],
            'extension' => ['id' => 'demo', 'name' => 'Demo', 'defaults' => ['enabled' => false]],
            'compatiblePanelVersions' => ['>=Alpha 4.0 <Alpha 5.0'],
            'capabilities' => [],
            'requirements' => $requirements,
            'files' => [[
                'path' => 'app/Extensions/Packages/demo/README.md',
                'sha256' => str_repeat('a', 64),
            ]],
        ]);
    }

    public function testInstalledPhpExtensionAndPanelServicesPass(): void
    {
        app(ExtensionRequirementService::class)->assertSatisfied($this->manifest([
            'phpExtensions' => ['json'],
            'panelServices' => ['http-client', 'queue', 'schedule', 'secrets', 'hooks'],
        ]));

        $this->assertTrue(true);
    }

    public function testMissingPhpExtensionIsRejected(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('PHP extension "m12labs_definitely_missing"');

        app(ExtensionRequirementService::class)->assertSatisfied($this->manifest([
            'phpExtensions' => ['m12labs_definitely_missing'],
        ]));
    }
}
