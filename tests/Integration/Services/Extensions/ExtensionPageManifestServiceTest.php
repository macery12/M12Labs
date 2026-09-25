<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Illuminate\Support\Facades\File;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Extensions\ExtensionPageManifestService;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;

/**
 * The page manifest the panel generates for the frontend build.
 *
 * The whole reason it is panel-written rather than package-shipped is that it
 * decides nav category, permission and label for every page — the exact set of
 * claims the manifest parser verifies. These tests pin that the generated file
 * comes from the parsed manifest and carries the panel's derivations, not the
 * package's raw strings.
 */
class ExtensionPageManifestServiceTest extends IntegrationTestCase
{
    private ExtensionPageManifestService $service;

    private string $originalBasePath;

    private string $workspace;

    public function setUp(): void
    {
        parent::setUp();

        $this->originalBasePath = $this->app->basePath();
        $this->workspace = sys_get_temp_dir() . '/extension-pages-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->workspace);
        $this->app->setBasePath($this->workspace);
        $this->service = new ExtensionPageManifestService();
    }

    public function tearDown(): void
    {
        $this->app->setBasePath($this->originalBasePath);
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    private function manifest(array $capabilities, array $files): \Everest\Services\Extensions\Manifest\ExtensionManifest
    {
        return app(ExtensionManifestParser::class)->parse([
            'manifestVersion' => 3,
            'package' => ['id' => 'pagedemo', 'version' => '1.2.0', 'publisher' => 'm12labs'],
            'extension' => [
                'id' => 'pagedemo',
                'name' => 'Page Demo',
                'description' => 'Fixture.',
                'icon' => 'puzzle',
                'defaults' => ['enabled' => false],
            ],
            'compatiblePanelVersions' => ['>=Alpha 4.0 <Alpha 5.0'],
            'capabilities' => $capabilities,
            'files' => array_map(
                fn (string $path): array => ['path' => $path, 'sha256' => str_repeat('0', 64)],
                $files
            ),
        ], 'pagedemo');
    }

    public function testItWritesEveryDeclaredPageInDeclaredOrder(): void
    {
        $manifest = $this->manifest(
            [
                'pages' => [
                    'server' => [
                        ['slug' => 'overview', 'labelKey' => 'ext.pagedemo.nav.overview', 'icon' => 'server', 'category' => 'general', 'order' => 10],
                        ['slug' => 'logs', 'labelKey' => 'ext.pagedemo.nav.logs', 'icon' => 'database', 'category' => 'data', 'order' => 20, 'requiredServerPermission' => 'file.read'],
                    ],
                ],
            ],
            [
                'frontend/src/extensions/packages/pagedemo/pages/server/overview.tsx',
                'frontend/src/extensions/packages/pagedemo/pages/server/logs.tsx',
            ]
        );

        $plan = $this->service->write($manifest, $this->workspace . '/backups');

        $this->assertSame('generated', $plan['operation']);
        $this->assertSame('frontend/src/extensions/packages/pagedemo/extension.pages.json', $plan['path']);

        $written = json_decode((string) file_get_contents($plan['targetPath']), true);

        $this->assertSame('pagedemo', $written['id']);
        $this->assertSame('Page Demo', $written['name']);
        $this->assertSame('1.2.0', $written['version']);
        $this->assertCount(2, $written['server']);
        $this->assertSame(['overview', 'logs'], array_column($written['server'], 'slug'));
        $this->assertSame('general', $written['server'][0]['category']);
        $this->assertSame('file.read', $written['server'][1]['requiredServerPermission']);
        $this->assertSame([], $written['admin']);
    }

    /**
     * The manifest names only the action; the panel derives the full
     * capability identifier. Doing that in the browser instead would let a
     * package influence which permission is checked.
     */
    public function testAdminPagePermissionsAreExpandedToFullIdentifiers(): void
    {
        $manifest = $this->manifest(
            [
                'routes' => ['admin' => true],
                'permissions' => ['admin' => [['key' => 'read', 'labelKey' => 'ext.pagedemo.permission.read']]],
                'pages' => [
                    'admin' => [[
                        'slug' => 'dashboard',
                        'labelKey' => 'ext.pagedemo.nav.dashboard',
                        'icon' => 'server',
                        'category' => 'modules',
                        'order' => 5,
                        'requiredExtensionPermission' => 'read',
                    ]],
                ],
            ],
            [
                'app/Extensions/Packages/pagedemo/routes/admin.php',
                'frontend/src/extensions/packages/pagedemo/pages/admin/dashboard.tsx',
            ]
        );

        $written = json_decode((string) file_get_contents($this->service->write($manifest, $this->workspace . '/backups')['targetPath']), true);

        $this->assertSame('ext.pagedemo.admin.read', $written['admin'][0]['requiredPermission']);
        $this->assertSame('modules', $written['admin'][0]['category']);
    }

    public function testItWritesTheAdminNavEntryOnlyWhenDeclared(): void
    {
        $pages = ['admin' => [[
            'slug' => 'dashboard',
            'labelKey' => 'ext.pagedemo.nav.dashboard',
            'icon' => 'server',
            'category' => 'modules',
            'order' => 5,
        ]]];
        $files = ['frontend/src/extensions/packages/pagedemo/pages/admin/dashboard.tsx'];

        $declared = $this->manifest(
            ['pages' => $pages, 'nav' => ['admin' => ['labelKey' => 'ext.pagedemo.nav.group', 'icon' => 'sparkles', 'order' => 15]]],
            $files,
        );
        $written = json_decode((string) file_get_contents($this->service->write($declared, $this->workspace . '/backups')['targetPath']), true);

        $this->assertSame(['labelKey' => 'ext.pagedemo.nav.group', 'icon' => 'sparkles', 'order' => 15], $written['nav']['admin']);

        $undeclared = $this->manifest(['pages' => $pages], $files);
        $written = json_decode((string) file_get_contents($this->service->write($undeclared, $this->workspace . '/backups')['targetPath']), true);

        $this->assertArrayNotHasKey('nav', $written);
    }

    public function testItWritesVerifiedFrontendSlotsForTheLazyRegistry(): void
    {
        $manifest = $this->manifest(
            ['slots' => [[
                'name' => 'server-layout.overlay',
                'entry' => 'assistant-drawer',
                'order' => 25,
                'requiredServerPermission' => 'control.console',
            ]]],
            ['frontend/src/extensions/packages/pagedemo/slots/assistant-drawer.tsx']
        );

        $written = json_decode((string) file_get_contents($this->service->write($manifest, $this->workspace . '/backups')['targetPath']), true);

        $this->assertSame([[
            'name' => 'server-layout.overlay',
            'entry' => 'assistant-drawer',
            'order' => 25,
            'requiredServerPermission' => 'control.console',
        ]], $written['slots']);
    }

    public function testItWritesOnlyDeclaredFlagNamesOntoPagesAndSlots(): void
    {
        $manifest = $this->manifest(
            [
                'settings' => ['fields' => [[
                    'key' => 'assistant_enabled',
                    'type' => 'boolean',
                    'labelKey' => 'ext.pagedemo.assistant_enabled',
                ]]],
                'flags' => [[
                    'name' => 'assistant-ready',
                    'all' => [['setting' => 'assistant_enabled', 'equals' => true]],
                ]],
                'pages' => ['admin' => [[
                    'slug' => 'assistant',
                    'labelKey' => 'ext.pagedemo.nav.assistant',
                    'icon' => 'bot',
                    'category' => 'modules',
                    'requiredFlags' => ['assistant-ready'],
                ]]],
                'slots' => [[
                    'name' => 'server-layout.overlay',
                    'entry' => 'assistant-drawer',
                    'requiredFlags' => ['assistant-ready'],
                ]],
            ],
            [
                'frontend/src/extensions/packages/pagedemo/pages/admin/assistant.tsx',
                'frontend/src/extensions/packages/pagedemo/slots/assistant-drawer.tsx',
            ]
        );

        $written = json_decode((string) file_get_contents($this->service->write($manifest, $this->workspace . '/backups')['targetPath']), true);

        $this->assertSame(['assistant-ready'], $written['admin'][0]['requiredFlags']);
        $this->assertSame(['assistant-ready'], $written['slots'][0]['requiredFlags']);
        $this->assertArrayNotHasKey('flags', $written);
    }

    /** The checksum in the plan matches what actually landed on disk. */
    public function testTheGeneratedPlanChecksumMatchesTheFile(): void
    {
        $manifest = $this->manifest(
            ['pages' => ['server' => [['slug' => 'overview', 'labelKey' => 'ext.pagedemo.nav.overview', 'icon' => 'server', 'category' => 'general', 'order' => 1]]]],
            ['frontend/src/extensions/packages/pagedemo/pages/server/overview.tsx']
        );

        $plan = $this->service->write($manifest, $this->workspace . '/backups');

        $this->assertSame(hash_file('sha256', $plan['targetPath']), $plan['checksum']);
    }

    public function testRemoveDeletesTheGeneratedFile(): void
    {
        $manifest = $this->manifest(
            ['pages' => ['server' => [['slug' => 'overview', 'labelKey' => 'ext.pagedemo.nav.overview', 'icon' => 'server', 'category' => 'general', 'order' => 1]]]],
            ['frontend/src/extensions/packages/pagedemo/pages/server/overview.tsx']
        );

        $plan = $this->service->write($manifest, $this->workspace . '/backups');
        $this->assertFileExists($plan['targetPath']);

        $this->service->remove('pagedemo');
        $this->assertFileDoesNotExist($plan['targetPath']);
    }

    public function testItBacksUpAPreExistingUntrackedGeneratedTarget(): void
    {
        $manifest = $this->manifest([], [
            'frontend/src/extensions/packages/pagedemo/pages/server/overview.tsx',
        ]);
        $target = base_path($this->service->relativePath('pagedemo'));
        File::ensureDirectoryExists(dirname($target));
        File::put($target, 'pre-existing panel file');

        $plan = $this->service->write($manifest, $this->workspace . '/backups');

        $this->assertSame('updated', $plan['operation']);
        $this->assertSame('pre-existing panel file', File::get($plan['backupPath']));
        $this->assertSame(hash('sha256', 'pre-existing panel file'), $plan['backupChecksum']);
    }
}
