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

        $this->scanner = app(ExtensionFrontendImportScanner::class);
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

    public function testUnknownBareImportIsRefusedBeforeTheBuild(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('requirements.npmPackages');
        $this->expectExceptionMessage('lucide-recat');

        $this->scanner->assertOnlySdkImports($this->plans([
            self::FRONTEND => "import { Cat } from 'lucide-recat';\n",
        ]));
    }

    public function testTransitiveLockfilePackageIsNotTreatedAsPanelProvided(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('micromark');

        $this->scanner->assertOnlySdkImports($this->plans([
            self::FRONTEND => "import { parse } from 'micromark';\n",
        ]));
    }

    public function testDeclaredBarePackageAndPanelProvidedSubpathAreAllowed(): void
    {
        $this->scanner->assertOnlySdkImports($this->plans([
            self::FRONTEND => <<<'TSX'
                import jsx from 'react/jsx-runtime';
                import thing from '@example/future-package/subpath';
                TSX,
        ]), ['@example/future-package' => '^1.0']);

        $this->assertTrue(true);
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

    public function testSdkAliasImportCannotTraverseIntoPanelInternals(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('@/extensions-sdk/../lib/http');

        $this->scanner->assertOnlySdkImports($this->plans([
            self::FRONTEND => "import http from '@/extensions-sdk/../lib/http';\n",
        ]));
    }

    public function testBacktickDynamicImportIsRefused(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('@/lib/http');

        $this->scanner->assertOnlySdkImports($this->plans([
            self::FRONTEND => 'const http = await import(`@/lib/http`);',
        ]));
    }

    public function testRelativeImportCannotEscapeItsPackage(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessageMatches('~imports ../../../../lib/http~');

        $this->scanner->assertOnlySdkImports($this->plans([
            self::FRONTEND => "import http from '../../../../lib/http';\n",
        ]));
    }

    public function testViteRootAbsoluteImportIsRefused(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessageMatches('~imports /src/lib/http~');

        $this->scanner->assertOnlySdkImports($this->plans([
            self::FRONTEND => "import http from '/src/lib/http';\n",
        ]));
    }

    public function testEscapedModuleSpecifierIsRefused(): void
    {
        $this->expectException(DisplayException::class);

        $this->scanner->assertOnlySdkImports($this->plans([
            self::FRONTEND => <<<'TSX'
                import http from '@\/lib/http';
                TSX,
        ]));
    }

    public function testRelativeImportMayStayInsideItsPackage(): void
    {
        $this->scanner->assertOnlySdkImports($this->plans([
            self::FRONTEND => "import api from '../../api';\n",
        ]));

        $this->assertTrue(true);
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

    public function testCommentMarkerInsideAStringCannotHideALaterImport(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('@/lib/http');

        $this->scanner->assertOnlySdkImports($this->plans([
            self::FRONTEND => <<<'TSX'
                const marker = '//'; import http from '@/lib/http';
                TSX,
        ]));
    }

    public function testDoubledSlashInsideAModulePathCannotHideIt(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('@/lib//http');

        $this->scanner->assertOnlySdkImports($this->plans([
            self::FRONTEND => "import http from '@/lib//http';\n",
        ]));
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
     * Read the published source tree when it is available locally. Tightening
     * the bare-import gate must not quietly strand an existing v3 package.
     */
    public function testTheShippedExtensionPackagesPass(): void
    {
        $extensionsRoot = '/var/www/M12Labs-Extensions/extensions';
        if (!is_dir($extensionsRoot)) {
            $this->markTestSkipped('The extensions repository is not present on this machine.');
        }

        $checked = [];
        foreach (File::directories($extensionsRoot) as $extensionRoot) {
            $filesRoot = $extensionRoot . '/files';
            $frontendRoot = $filesRoot . '/frontend';
            if (!is_dir($frontendRoot)) {
                continue;
            }

            $plans = [];
            foreach (File::allFiles($frontendRoot) as $file) {
                $plans[] = [
                    'path' => ltrim(str_replace($filesRoot, '', $file->getPathname()), '/'),
                    'sourcePath' => $file->getPathname(),
                ];
            }

            $manifest = json_decode((string) File::get($extensionRoot . '/extension.json'), true, 512, JSON_THROW_ON_ERROR);
            $declared = (array) ($manifest['requirements']['npmPackages'] ?? []);
            $extension = basename($extensionRoot);

            try {
                $this->scanner->assertOnlySdkImports($plans, $declared);
            } catch (DisplayException $exception) {
                $this->fail(sprintf('Published extension "%s" failed its frontend import scan: %s', $extension, $exception->getMessage()));
            }

            $checked[] = $extension;
        }

        $this->assertNotEmpty($checked, 'Expected at least one published package to ship frontend files.');
    }
}
