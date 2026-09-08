<?php

namespace Everest\Tests\Unit\Services\Extensions\Manifest;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityFileValidator;

/**
 * Declared capabilities and shipped files must agree in both directions.
 *
 * A capability without its file fails at runtime in whatever way the missing
 * file happens to fail. A file without its capability is worse: executable code
 * the administrator was never shown and never approved.
 */
class ExtensionCapabilityFileValidatorTest extends TestCase
{
    private ExtensionManifestParser $parser;

    private ExtensionCapabilityFileValidator $validator;

    public function setUp(): void
    {
        parent::setUp();

        $this->parser = new ExtensionManifestParser();
        $this->validator = new ExtensionCapabilityFileValidator();
    }

    /**
     * @param array<string, mixed> $capabilities
     * @param array<int, string> $paths
     */
    private function validate(array $capabilities, array $paths): void
    {
        $manifest = $this->parser->parse([
            'manifestVersion' => 3,
            'package' => ['id' => 'demo', 'version' => '1.0.0'],
            'extension' => ['id' => 'demo', 'name' => 'Demo', 'icon' => 'puzzle'],
            'compatiblePanelVersions' => ['>=Alpha 4.0 <Alpha 5.0'],
            'capabilities' => $capabilities,
            'files' => array_map(fn (string $path): array => ['path' => $path, 'sha256' => str_repeat('a', 64)], $paths),
        ]);

        $this->validator->assertMatchesFiles($manifest);
    }

    public function testAcceptsAMatchingPackage(): void
    {
        $this->validate(
            [
                'routes' => ['client' => true, 'admin' => true],
                'pages' => [
                    'server' => [['slug' => 'main', 'labelKey' => 'ext.demo.nav', 'icon' => 'globe', 'category' => 'configuration']],
                    'admin' => [['slug' => 'settings', 'labelKey' => 'ext.demo.admin', 'icon' => 'globe', 'category' => 'modules']],
                ],
                'hooks' => [['event' => 'server.created', 'handler' => 'Sync', 'mode' => 'queued_at_least_once']],
                'queues' => [['name' => 'dns']],
                'schedule' => true,
                'database' => ['migrations' => true],
            ],
            [
                'app/Extensions/Packages/demo/routes/client.php',
                'app/Extensions/Packages/demo/routes/admin.php',
                'app/Extensions/Packages/demo/Hooks/Sync.php',
                'app/Extensions/Packages/demo/Jobs/SyncJob.php',
                'app/Extensions/Packages/demo/schedule.php',
                'app/Extensions/Packages/demo/database/migrations/2026_01_01_000000_create_ext_demo_records_table.php',
                'frontend/src/extensions/packages/demo/pages/server/main.tsx',
                'frontend/src/extensions/packages/demo/pages/admin/settings.tsx',
            ]
        );

        $this->addToAssertionCount(1);
    }

    public function testRejectsADeclaredCapabilityWithNoFile(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('declares "capabilities.routes.admin" but the package does not ship');

        // Declares admin routes and ships only the client route file it also
        // declares, so the admin declaration is the single unmatched rule.
        $this->validate(
            ['routes' => ['client' => true, 'admin' => true]],
            ['app/Extensions/Packages/demo/routes/client.php']
        );
    }

    /** Code the administrator never approved must not ride along in the archive. */
    public function testRejectsAnUndeclaredRouteFile(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not declare "capabilities.routes.client"');

        $this->validate([], ['app/Extensions/Packages/demo/routes/client.php']);
    }

    public function testRejectsAnUndeclaredHookClass(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not declare it under capabilities.hooks');

        $this->validate(
            ['routes' => ['client' => true]],
            [
                'app/Extensions/Packages/demo/routes/client.php',
                'app/Extensions/Packages/demo/Hooks/Secret.php',
            ]
        );
    }

    public function testRejectsAnUndeclaredPage(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not declare it under capabilities.pages.admin');

        $this->validate(
            ['routes' => ['client' => true]],
            [
                'app/Extensions/Packages/demo/routes/client.php',
                'frontend/src/extensions/packages/demo/pages/admin/hidden.tsx',
            ]
        );
    }

    /** Supporting components beside a page are fine; only the entry is declared. */
    public function testAllowsSupportingComponentsInASubdirectory(): void
    {
        $this->validate(
            [
                'routes' => ['client' => true],
                'pages' => ['server' => [['slug' => 'main', 'labelKey' => 'ext.demo.nav', 'icon' => 'globe', 'category' => 'data']]],
            ],
            [
                'app/Extensions/Packages/demo/routes/client.php',
                'frontend/src/extensions/packages/demo/pages/server/main.tsx',
                'frontend/src/extensions/packages/demo/pages/server/parts/Table.tsx',
            ]
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The v2 layout inferred surfaces from meta.json and fixed filenames.
     * Accepting it would reintroduce the inference v3 exists to remove.
     */
    public function testRejectsTheRetiredV2Layout(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('retired v2 layout');

        $this->validate(
            ['routes' => ['client' => true]],
            [
                'app/Extensions/Packages/demo/routes/client.php',
                'frontend/src/extensions/packages/demo/meta.json',
            ]
        );
    }
}
