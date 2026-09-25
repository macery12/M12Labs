<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Illuminate\Support\Facades\File;
use Everest\Exceptions\DisplayException;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Extensions\ExtensionArchiveExtractor;

/**
 * A package archive is attacker-controlled input until its checksums and
 * signature verify — and that happens after extraction, so the extractor is
 * the boundary. These build genuinely hostile archives and assert none of them
 * gets to write anything.
 */
class ExtensionArchiveExtractorTest extends IntegrationTestCase
{
    private string $workspace;

    public function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir() . '/ext-archive-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->workspace . '/out');
    }

    public function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    private function extractor(): ExtensionArchiveExtractor
    {
        return app(ExtensionArchiveExtractor::class);
    }

    /** @param callable(\ZipArchive):void $build */
    private function archive(callable $build): string
    {
        $path = $this->workspace . '/a-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $build($zip);
        $zip->close();

        return $path;
    }

    private function out(): string
    {
        return $this->workspace . '/out';
    }

    public function testExtractsAWellFormedArchive(): void
    {
        $archive = $this->archive(function (\ZipArchive $zip): void {
            $zip->addFromString('m12labs-extension.json', '{"manifestVersion":3}');
            $zip->addFromString('app/Extensions/Packages/demo/routes/client.php', '<?php');
        });

        $this->extractor()->extract($archive, $this->out());

        $this->assertFileExists($this->out() . '/m12labs-extension.json');
        $this->assertFileExists($this->out() . '/app/Extensions/Packages/demo/routes/client.php');
        // The panel chooses the mode, so nothing lands executable.
        $this->assertSame('0644', substr(sprintf('%o', fileperms($this->out() . '/m12labs-extension.json')), -4));
    }

    /** The classic zip-slip: an entry that writes outside the destination. */
    public function testRejectsPathTraversal(): void
    {
        $archive = $this->archive(fn (\ZipArchive $zip) => $zip->addFromString('../../../etc/pwned', 'x'));

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('traversal path');

        $this->extractor()->extract($archive, $this->out());
    }

    public function testRejectsAnAbsolutePath(): void
    {
        $archive = $this->archive(fn (\ZipArchive $zip) => $zip->addFromString('/etc/pwned', 'x'));

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('absolute path');

        $this->extractor()->extract($archive, $this->out());
    }

    public function testRejectsAWindowsDrivePath(): void
    {
        $archive = $this->archive(fn (\ZipArchive $zip) => $zip->addFromString('C:/pwned', 'x'));

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('drive-qualified path');

        $this->extractor()->extract($archive, $this->out());
    }

    public function testRejectsAmbiguousUnicodeSeparators(): void
    {
        $archive = $this->archive(fn (\ZipArchive $zip) => $zip->addFromString("app\u{2215}..\u{2215}pwned", 'x'));

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('ambiguous path separator');

        $this->extractor()->extract($archive, $this->out());
    }

    /**
     * A symlink in an archive is a write primitive: extract one aimed at a core
     * file, then extract "through" it.
     */
    public function testRejectsASymbolicLink(): void
    {
        $archive = $this->archive(function (\ZipArchive $zip): void {
            $zip->addFromString('link', '/var/www/m12labs/app/Http/Kernel.php');
            $zip->setExternalAttributesName('link', \ZipArchive::OPSYS_UNIX, 0o120777 << 16);
        });

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('symbolic link');

        $this->extractor()->extract($archive, $this->out());
    }

    public function testRejectsSetuidEntries(): void
    {
        $archive = $this->archive(function (\ZipArchive $zip): void {
            $zip->addFromString('tool', 'x');
            $zip->setExternalAttributesName('tool', \ZipArchive::OPSYS_UNIX, 0o104755 << 16);
        });

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('setuid, setgid or sticky bits');

        $this->extractor()->extract($archive, $this->out());
    }

    public function testRejectsAnExecutableFile(): void
    {
        $archive = $this->archive(function (\ZipArchive $zip): void {
            $zip->addFromString('run.sh', '#!/bin/sh');
            $zip->setExternalAttributesName('run.sh', \ZipArchive::OPSYS_UNIX, 0o100755 << 16);
        });

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('executable file');

        $this->extractor()->extract($archive, $this->out());
    }

    public function testRejectsTooManyEntries(): void
    {
        config()->set('extensions.archive.max_entries', 5);

        $archive = $this->archive(function (\ZipArchive $zip): void {
            for ($i = 0; $i < 10; ++$i) {
                $zip->addFromString("file{$i}.txt", 'x');
            }
        });

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('more than the permitted');

        $this->extractor()->extract($archive, $this->out());
    }

    public function testRejectsAnOversizedEntry(): void
    {
        config()->set('extensions.archive.max_file_bytes', 128);

        $archive = $this->archive(fn (\ZipArchive $zip) => $zip->addFromString('big.txt', str_repeat('a', 4096)));

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('expands to more than the permitted');

        $this->extractor()->extract($archive, $this->out());
    }

    /** A zip bomb: a few kilobytes that expand into far more. */
    public function testRejectsAnImplausibleCompressionRatio(): void
    {
        config()->set('extensions.archive.max_file_bytes', 64 * 1024 * 1024);
        config()->set('extensions.archive.max_total_bytes', 64 * 1024 * 1024);
        config()->set('extensions.archive.max_expansion_ratio', 50);

        $archive = $this->archive(fn (\ZipArchive $zip) => $zip->addFromString('bomb.txt', str_repeat("\0", 8 * 1024 * 1024)));

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('implausible compression ratio');

        $this->extractor()->extract($archive, $this->out());
    }

    public function testRejectsAnExcessivelyDeepPath(): void
    {
        config()->set('extensions.archive.max_path_depth', 3);

        $archive = $this->archive(fn (\ZipArchive $zip) => $zip->addFromString('a/b/c/d/e/f.txt', 'x'));

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('deeper than 3 segments');

        $this->extractor()->extract($archive, $this->out());
    }

    /** Nothing may be written before every entry has been inspected. */
    public function testNothingIsWrittenWhenAnyEntryIsRejected(): void
    {
        $archive = $this->archive(function (\ZipArchive $zip): void {
            $zip->addFromString('safe.txt', 'x');
            $zip->addFromString('../escape.txt', 'x');
        });

        try {
            $this->extractor()->extract($archive, $this->out());
            $this->fail('expected the archive to be rejected');
        } catch (DisplayException) {
            // expected
        }

        $this->assertFileDoesNotExist($this->out() . '/safe.txt');
        $this->assertCount(0, File::allFiles($this->out()));
    }
}
