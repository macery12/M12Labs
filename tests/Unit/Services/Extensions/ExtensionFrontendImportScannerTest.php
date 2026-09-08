<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionFrontendImportScanner;

/**
 * The gate A6a specified and never got: without it, nothing stopped a package
 * importing panel internals, and compliance was author discipline.
 */
class ExtensionFrontendImportScannerTest extends TestCase
{
    private ExtensionFrontendImportScanner $scanner;

    private string $root;

    public function setUp(): void
    {
        parent::setUp();

        $this->scanner = new ExtensionFrontendImportScanner();
        $this->root = sys_get_temp_dir() . '/import-scan-' . uniqid();
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * @param array<string, string> $files path => contents
     *
     * @return array<int, array{path: string, sourcePath: string}>
     */
    private function plans(array $files): array
    {
        $plans = [];
        foreach ($files as $path => $contents) {
            $source = $this->root . '/' . $path;
            File::ensureDirectoryExists(dirname($source));
            File::put($source, $contents);
            $plans[] = ['path' => $path, 'sourcePath' => $source];
        }

        return $plans;
    }

    private const FRONTEND = 'frontend/src/extensions/packages/demo/pages/admin/thing.tsx';

    public function testSdkImportsAreAllowed(): void
    {
        $this->scanner->assertOnlySdkImports($this->plans([
            self::FRONTEND => <<<'TSX'
                import { useState } from 'react';
                import { useQuery } from '@tanstack/react-query';
                import { Button, createTranslator } from '@/extensions-sdk';
                import { extensionQueryKey } from '@/extensions-sdk/query';
                import { getThings } from '../../api';
                TSX,
        ]));

        $this->assertTrue(true, 'A package using only the SDK installs.');
    }

    public function testAPanelInternalImportIsRefused(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessageMatches('~imports @/lib/http~');

        $this->scanner->assertOnlySdkImports($this->plans([
            self::FRONTEND => "import http from '@/lib/http';\n",
        ]));
    }

    /**
     * A re-export and a dynamic import bring the module in just as surely as a
     * static import does.
     */
    public function testReExportsAndDynamicImportsAreCaught(): void
    {
        try {
            $this->scanner->assertOnlySdkImports($this->plans([
                self::FRONTEND => <<<'TSX'
                    export { useFlashes } from '@/state/flashes';
                    const mod = await import('@/api/servers');
                    TSX,
            ]));
            $this->fail('Expected the scan to refuse both forms.');
        } catch (DisplayException $exception) {
            $this->assertStringContainsString('@/state/flashes', $exception->getMessage());
            $this->assertStringContainsString('@/api/servers', $exception->getMessage());
        }
    }

    /**
     * Every violation in one message. Learning the supported surface one failed
     * install at a time is miserable.
     */
    public function testAllViolationsAreReportedTogether(): void
    {
        try {
            $this->scanner->assertOnlySdkImports($this->plans([
                self::FRONTEND => "import a from '@/lib/cn';\nimport b from '@/lib/can';\n",
                'frontend/src/extensions/packages/demo/api.ts' => "import http from '@/lib/http';\n",
            ]));
            $this->fail('Expected a refusal.');
        } catch (DisplayException $exception) {
            foreach (['@/lib/cn', '@/lib/can', '@/lib/http'] as $specifier) {
                $this->assertStringContainsString($specifier, $exception->getMessage());
            }
        }
    }

    /**
     * A prefix match would admit a sibling directory that merely starts the
     * same way.
     */
    public function testASiblingOfTheSdkDirectoryIsNotAdmitted(): void
    {
        $this->expectException(DisplayException::class);

        $this->scanner->assertOnlySdkImports($this->plans([
            self::FRONTEND => "import x from '@/extensions-sdk-internal/secret';\n",
        ]));
    }

    /**
     * A package explaining in prose why it does not import something should not
     * be refused for saying so.
     */
    public function testCommentedOutImportsAreIgnored(): void
    {
        $this->scanner->assertOnlySdkImports($this->plans([
            self::FRONTEND => <<<'TSX'
                // Deliberately not: import http from '@/lib/http';
                /* nor import { x } from '@/state/flashes'; */
                import { Button } from '@/extensions-sdk';
                TSX,
        ]));

        $this->assertTrue(true, 'Comments are prose, not imports.');
    }

    /**
     * The scan is about the frontend surface. Package PHP legitimately uses the
     * panel's own namespaces, and a shipped .json or .md has no imports at all.
     */
    public function testBackendAndNonSourceFilesAreNotScanned(): void
    {
        $this->scanner->assertOnlySdkImports($this->plans([
            'app/Extensions/Packages/demo/Services/Thing.php' => "<?php\n// from '@/lib/http'\n",
            'frontend/src/extensions/packages/demo/messages/en.json' => '{"ext.demo.a":"from \'@/lib/http\'"}',
        ]));

        $this->assertTrue(true, 'Only frontend source is in scope.');
    }

    /**
     * The package B2 shipped, read from the published source tree. If the gate
     * and the pilot package ever disagree, one of them is wrong.
     */
    public function testTheShippedCustomDomainsPackagePasses(): void
    {
        $files = '/var/www/M12Labs-Extensions/extensions/custom_domains/files';
        if (!is_dir($files)) {
            $this->markTestSkipped('The extensions repository is not present on this machine.');
        }

        $plans = [];
        foreach (File::allFiles($files . '/frontend') as $file) {
            $plans[] = [
                'path' => 'frontend/' . ltrim(str_replace($files . '/frontend', '', $file->getPathname()), '/'),
                'sourcePath' => $file->getPathname(),
            ];
        }

        $this->assertNotEmpty($plans, 'Expected the package to ship frontend files.');
        $this->scanner->assertOnlySdkImports($plans);
    }
}
