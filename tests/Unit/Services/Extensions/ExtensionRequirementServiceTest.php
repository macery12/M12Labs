<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\Manifest\ExtensionManifest;
use Everest\Services\Extensions\ExtensionRequirementService;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;
use Everest\Exceptions\Service\Extension\PackageRequirementsNotSatisfiedException;

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

    public function testCompatibleDirectLockedNpmPackagesPass(): void
    {
        app(ExtensionRequirementService::class)->assertSatisfied($this->manifest([
            'npmPackages' => [
                'react' => '^19.2',
                '@tanstack/react-query' => '>=5.100 <6',
            ],
        ]));

        $this->assertTrue(true);
    }

    public function testMissingOrTransitiveNpmPackageIsRejected(): void
    {
        $this->expectException(PackageRequirementsNotSatisfiedException::class);
        $this->expectExceptionMessage('npm package "@radix-ui/react-compose-refs"');
        $this->expectExceptionMessage('is not installed');

        // Radix's compose-refs helper is present transitively, but pnpm does
        // not expose that as a stable import contract.
        app(ExtensionRequirementService::class)->assertSatisfied($this->manifest([
            'npmPackages' => ['@radix-ui/react-compose-refs' => '^1.1'],
        ]));
    }

    public function testIncompatibleLockedNpmPackageVersionIsRejectedPrecisely(): void
    {
        $this->expectException(PackageRequirementsNotSatisfiedException::class);
        $this->expectExceptionMessage('npm package "react" requires >=20');
        $this->expectExceptionMessage('panel locks 19.2.8');

        app(ExtensionRequirementService::class)->assertSatisfied($this->manifest([
            'npmPackages' => ['react' => '>=20'],
        ]));
    }

    public function testProvidedNpmPackagesComeFromTheDirectFrontendImporter(): void
    {
        $packages = app(ExtensionRequirementService::class)->providedNpmPackages();

        $this->assertSame('19.2.8', $packages['react']);
        $this->assertSame('5.102.8', $packages['@tanstack/react-query']);
        $this->assertArrayNotHasKey('@radix-ui/react-compose-refs', $packages);
    }

    public function testCompatibleDirectLockedComposerPackagePasses(): void
    {
        app(ExtensionRequirementService::class)->assertSatisfied($this->manifest([
            'composerPackages' => ['guzzlehttp/guzzle' => '^8.1'],
        ]));

        $this->assertTrue(true);
    }

    public function testTransitiveComposerPackageProducesStructuredFailureAndSafeCommand(): void
    {
        try {
            app(ExtensionRequirementService::class)->assertSatisfied($this->manifest([
                'composerPackages' => ['league/commonmark' => '^2.6'],
            ]));
            $this->fail('A transitive Composer dependency must not satisfy an extension requirement.');
        } catch (PackageRequirementsNotSatisfiedException $exception) {
            $this->assertSame('demo', $exception->extensionId);
            $this->assertSame([[
                'type' => 'backend',
                'manager' => 'composer',
                'package' => 'league/commonmark',
                'required' => '^2.6',
                'installed' => null,
                'status' => 'missing',
            ]], $exception->problems);
            $this->assertSame("composer require 'league/commonmark:^2.6'", $exception->commands['composer']);
        }
    }

    public function testIncompatibleComposerPackageReportsExactLockedVersion(): void
    {
        try {
            app(ExtensionRequirementService::class)->assertSatisfied($this->manifest([
                'composerPackages' => ['guzzlehttp/guzzle' => '>=9'],
            ]));
            $this->fail('An incompatible direct Composer dependency must be rejected.');
        } catch (PackageRequirementsNotSatisfiedException $exception) {
            $this->assertSame('8.1.0', $exception->problems[0]['installed']);
            $this->assertSame('incompatible', $exception->problems[0]['status']);
        }
    }

    public function testProvidedComposerPackagesComeFromDirectProductionRequirements(): void
    {
        $packages = app(ExtensionRequirementService::class)->providedComposerPackages();

        $this->assertSame('8.1.0', $packages['guzzlehttp/guzzle']);
        $this->assertArrayNotHasKey('league/commonmark', $packages);
        $this->assertArrayNotHasKey('phpunit/phpunit', $packages);
    }

    public function testStructuredFailureIncludesAllFrontendAndBackendProblems(): void
    {
        try {
            app(ExtensionRequirementService::class)->assertSatisfied($this->manifest([
                'npmPackages' => ['missing-npm-package' => '^1.0'],
                'composerPackages' => ['missing-vendor/missing-package' => '^2.0'],
            ]));
            $this->fail('Missing packages must reject installation.');
        } catch (PackageRequirementsNotSatisfiedException $exception) {
            $this->assertSame(['frontend', 'backend'], array_column($exception->problems, 'type'));
            $this->assertSame(
                "pnpm --filter ./frontend add 'missing-npm-package@^1.0'",
                $exception->commands['npm']
            );
            $this->assertSame(
                "composer require 'missing-vendor/missing-package:^2.0'",
                $exception->commands['composer']
            );
        }
    }
}
