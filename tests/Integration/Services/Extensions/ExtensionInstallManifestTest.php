<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionPackage;
use Illuminate\Support\Facades\File;
use Everest\Exceptions\DisplayException;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Extensions\ExtensionRuntimeGate;
use Everest\Services\Extensions\ExtensionPackageArtifactService;

/**
 * End-to-end proof that manifest v3 gates a real archive.
 *
 * The unit tests cover the parser in isolation; these build actual
 * .M12LabsExtension zips and push them through the same inspection path an
 * install uses, so a package that would be rejected at install is rejected
 * while it is still a file on disk, with the same message.
 */
class ExtensionInstallManifestTest extends IntegrationTestCase
{
    private string $workspace;

    public function setUp(): void
    {
        parent::setUp();

        ExtensionRuntimeGate::flush();
        $this->workspace = sys_get_temp_dir() . '/ext-install-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->workspace);
    }

    public function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        ExtensionRuntimeGate::flush();

        parent::tearDown();
    }

    /**
     * Build a package archive whose declared checksums match its real contents.
     *
     * @param array<string, mixed> $capabilities
     * @param array<string, string> $files path => contents
     */
    private function archive(array $capabilities, array $files, ?callable $mutate = null): string
    {
        $manifest = [
            'manifestVersion' => 3,
            'package' => ['id' => 'demo', 'version' => '1.0.0', 'publisher' => 'm12labs'],
            'extension' => [
                'id' => 'demo',
                'name' => 'Demo',
                'description' => 'Fixture package.',
                'icon' => 'puzzle',
                'defaults' => ['enabled' => false],
            ],
            'compatiblePanelVersions' => ['>=Alpha 4.0 <Alpha 5.0'],
            'capabilities' => $capabilities,
            'files' => [],
        ];

        foreach ($files as $path => $contents) {
            $manifest['files'][] = ['path' => $path, 'sha256' => hash('sha256', $contents)];
        }

        if ($mutate !== null) {
            $manifest = $mutate($manifest);
        }

        $archivePath = $this->workspace . '/demo-' . bin2hex(random_bytes(4)) . '.M12LabsExtension';
        $zip = new \ZipArchive();
        $zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString(ExtensionPackageArtifactService::MANIFEST_FILENAME, json_encode($manifest));
        foreach ($files as $path => $contents) {
            $zip->addFromString($path, $contents);
        }
        $zip->close();

        return $archivePath;
    }

    private function service(): ExtensionPackageArtifactService
    {
        return app(ExtensionPackageArtifactService::class);
    }

    public function testInspectsAValidV3Package(): void
    {
        $archive = $this->archive(
            [
                'routes' => ['admin' => true],
                'pages' => ['admin' => [[
                    'slug' => 'overview',
                    'labelKey' => 'ext.demo.nav',
                    'icon' => 'server',
                    'category' => 'modules',
                    'requiredExtensionPermission' => 'read',
                ]]],
                'permissions' => ['admin' => [['key' => 'read', 'labelKey' => 'ext.demo.permission.read']]],
            ],
            [
                'app/Extensions/Packages/demo/routes/admin.php' => '<?php',
                'frontend/src/extensions/packages/demo/pages/admin/overview.tsx' => 'export default () => null;',
            ]
        );

        $inspected = $this->service()->inspectArchive($archive);

        $this->assertSame('demo', $inspected['extensionId']);
        $this->assertSame('1.0.0', $inspected['version']);
        $this->assertSame(1, $inspected['capabilities']['adminPermissions']);
        $this->assertTrue($inspected['capabilities']['adminRoutes']);
    }

    /** A v2 archive must not install against a panel that only gates v3. */
    public function testRejectsAV2Archive(): void
    {
        $archive = $this->archive([], ['app/Extensions/Packages/demo/routes/client.php' => '<?php'], function (array $manifest): array {
            $manifest['manifestVersion'] = 2;
            $manifest['extension']['route'] = 'demo';
            unset($manifest['capabilities']);

            return $manifest;
        });

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('requires manifest version 3');

        $this->service()->inspectArchive($archive);
    }

    /**
     * The archive is the untrusted input. A route file that the manifest never
     * declared is executable code the administrator would never have seen in
     * the install approval.
     */
    public function testRejectsAnArchiveShippingAnUndeclaredRouteFile(): void
    {
        $archive = $this->archive(
            ['routes' => ['admin' => true]],
            [
                'app/Extensions/Packages/demo/routes/admin.php' => '<?php',
                'app/Extensions/Packages/demo/routes/client.php' => '<?php // never declared',
            ]
        );

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not declare "capabilities.routes.client"');

        $this->service()->inspectArchive($archive);
    }

    /** Nothing may be installed outside the two roots owned by this extension. */
    public function testRejectsAPathOutsideTheInstallRoots(): void
    {
        $archive = $this->archive(
            ['routes' => ['admin' => true]],
            [
                'app/Extensions/Packages/demo/routes/admin.php' => '<?php',
                'app/Http/Kernel.php' => '<?php // hijack',
            ]
        );

        $this->expectException(DisplayException::class);

        $this->service()->inspectArchive($archive);
    }

    public function testRejectsAPackageClaimingAnotherExtensionsDirectory(): void
    {
        $archive = $this->archive(
            ['routes' => ['admin' => true]],
            ['app/Extensions/Packages/demo/routes/admin.php' => '<?php']
        );

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not match the requested extension id');

        $this->service()->parseManifest(
            $this->service()->inspectArchive($archive)['manifest'],
            'some_other_extension'
        );
    }

    public function testDiscoverArchivesSurfacesRejectionsWithoutThrowing(): void
    {
        $this->archive([], ['app/Extensions/Packages/demo/routes/client.php' => '<?php'], function (array $manifest): array {
            $manifest['manifestVersion'] = 2;
            unset($manifest['capabilities']);

            return $manifest;
        });

        $found = $this->service()->discoverArchives($this->workspace);

        $this->assertCount(1, $found);
        $this->assertArrayHasKey('error', $found[0]);
        $this->assertStringContainsString('manifest version 3', $found[0]['error']);
    }

    public function testNoPackageRowIsCreatedByInspection(): void
    {
        $this->archive(['routes' => ['admin' => true]], ['app/Extensions/Packages/demo/routes/admin.php' => '<?php']);

        $this->assertSame(0, ExtensionPackage::query()->where('extension_id', 'demo')->count());
    }
}
