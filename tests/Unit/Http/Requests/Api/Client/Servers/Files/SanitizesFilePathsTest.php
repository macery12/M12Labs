<?php

namespace Everest\Tests\Unit\Http\Requests\Api\Client\Servers\Files;

use Everest\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Contracts\Http\ClientPermissionsRequest;
use Everest\Http\Requests\Api\Client\ClientApiRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\CopyFileRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\PullFileRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\ListFilesRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\ChmodFilesRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\DeleteFileRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\RenameFileRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\CreateFolderRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\CompressFilesRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\DecompressFilesRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\GetFileContentsRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\WriteFileContentRequest;

/**
 * Path sanitization used to live only on rename, which is how it came to
 * disagree with the daemon's own path contract without anything noticing. These
 * cover the shared trait as applied to every file operation.
 */
class SanitizesFilePathsTest extends TestCase
{
    /**
     * @return array{errors: list<string>, input: array<string, mixed>}
     */
    private function sanitize(string $requestClass, array $body, string $method = 'POST'): array
    {
        /** @var ClientApiRequest $request */
        $request = $requestClass::create(
            '/api/client/servers/abc/files/x',
            $method,
            $method === 'GET' ? $body : [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $method === 'GET' ? '' : (string) json_encode($body)
        );
        $request->setContainer($this->app);

        $validator = $this->app->make(Factory::class)->make($request->all(), $request->rules());
        if (method_exists($request, 'withValidator')) {
            $request->withValidator($validator);
        }

        return [
            'errors' => $validator->fails() ? $validator->errors()->all() : [],
            'input' => $request->all(),
        ];
    }

    /**
     * Every file request must declare the interface rather than leaning on
     * ClientApiRequest::authorize()'s method_exists() fallback, which is one
     * refactor away from turning these into unauthenticated endpoints.
     */
    #[DataProvider('permissionedRequestProvider')]
    public function testFileRequestsDeclareThePermissionsContract(string $requestClass): void
    {
        $this->assertInstanceOf(ClientPermissionsRequest::class, new $requestClass());
    }

    public static function permissionedRequestProvider(): array
    {
        return [
            'list' => [ListFilesRequest::class],
            'contents' => [GetFileContentsRequest::class],
            'create folder' => [CreateFolderRequest::class],
            'compress' => [CompressFilesRequest::class],
            'delete' => [DeleteFileRequest::class],
            'chmod' => [ChmodFilesRequest::class],
            'copy' => [CopyFileRequest::class],
            'rename' => [RenameFileRequest::class],
            'decompress' => [DecompressFilesRequest::class],
            'pull' => [PullFileRequest::class],
            'write' => [WriteFileContentRequest::class],
        ];
    }

    /** Root-relative operations must hand the daemon the remainder below root. */
    public function testDeleteTargetsStayRelativeToTheRoot(): void
    {
        $result = $this->sanitize(DeleteFileRequest::class, [
            'root' => '/plugins',
            'files' => ['a.txt', 'nested/b.txt'],
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('/plugins', $result['input']['root']);
        $this->assertSame(['a.txt', 'nested/b.txt'], $result['input']['files']);
    }

    public function testCompressTargetsStayRelativeToTheRoot(): void
    {
        $result = $this->sanitize(CompressFilesRequest::class, [
            'root' => '/world (old)',
            'files' => ['region'],
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['region'], $result['input']['files']);
    }

    public function testChmodPreservesTheModeWhileSanitizingThePath(): void
    {
        $result = $this->sanitize(ChmodFilesRequest::class, [
            'root' => '/plugins',
            'files' => [['file' => 'a.sh', 'mode' => '755']],
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame([['file' => 'a.sh', 'mode' => '755']], $result['input']['files']);
    }

    #[DataProvider('chmodModeProvider')]
    public function testChmodModeMustBeOctal(mixed $mode, bool $shouldPass): void
    {
        $result = $this->sanitize(ChmodFilesRequest::class, [
            'root' => '/',
            'files' => [['file' => 'a.sh', 'mode' => $mode]],
        ]);

        $this->assertSame($shouldPass, $result['errors'] === [], implode(' ', $result['errors']));
    }

    public static function chmodModeProvider(): array
    {
        return [
            'three digits' => ['755', true],
            'leading zero' => ['0644', true],
            'numeric json' => [755, true],
            'out of range digit' => ['758', false],
            'too long' => ['07555', false],
            'not a number' => ['rwx', false],
        ];
    }

    public function testDecompressTargetStaysRelativeToTheRoot(): void
    {
        $result = $this->sanitize(DecompressFilesRequest::class, [
            'root' => '/backups',
            'file' => 'world.tar.gz',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('world.tar.gz', $result['input']['file']);
    }

    public function testCreateFolderNameStaysRelativeToTheRoot(): void
    {
        $result = $this->sanitize(CreateFolderRequest::class, [
            'root' => '/plugins',
            'name' => 'config/backups',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('config/backups', $result['input']['name']);
    }

    /** Copy takes a whole path from the server root, not a root-relative name. */
    public function testCopyLocationIsNormalizedAsServerAbsolute(): void
    {
        $result = $this->sanitize(CopyFileRequest::class, ['location' => 'plugins//../plugins/a.txt']);
        $this->assertNotSame([], $result['errors']);

        $result = $this->sanitize(CopyFileRequest::class, ['location' => 'plugins/a.txt']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('/plugins/a.txt', $result['input']['location']);
    }

    public function testListDirectoryDefaultsToTheServerRoot(): void
    {
        $result = $this->sanitize(ListFilesRequest::class, [], 'GET');

        $this->assertSame([], $result['errors']);
        $this->assertSame('/', $result['input']['directory']);
    }

    /** download-directory shares this request, so the root is a valid target. */
    public function testFileContentsAllowsTheServerRoot(): void
    {
        $result = $this->sanitize(GetFileContentsRequest::class, ['file' => '/'], 'GET');

        $this->assertSame([], $result['errors']);
        $this->assertSame('/', $result['input']['file']);
    }

    public function testWriteTargetMayNotBeTheServerRoot(): void
    {
        $result = $this->sanitize(WriteFileContentRequest::class, ['file' => '/'], 'GET');

        $this->assertNotSame([], $result['errors']);
    }

    /** A pull filename may not redirect the download out of its directory. */
    public function testPullFilenameMustBeABareName(): void
    {
        $result = $this->sanitize(PullFileRequest::class, [
            'url' => 'https://example.com/a.jar',
            'directory' => '/mods',
            'filename' => '../../escaped.jar',
        ]);
        $this->assertNotSame([], $result['errors']);

        $result = $this->sanitize(PullFileRequest::class, [
            'url' => 'https://example.com/a.jar',
            'directory' => '/mods',
            'filename' => 'nested/a.jar',
        ]);
        $this->assertNotSame([], $result['errors']);

        $result = $this->sanitize(PullFileRequest::class, [
            'url' => 'https://example.com/a.jar',
            'directory' => '/mods',
            'filename' => 'a.jar',
        ]);
        $this->assertSame([], $result['errors']);
        $this->assertSame('/mods', $result['input']['directory']);
    }

    #[DataProvider('pullSchemeProvider')]
    public function testPullOnlyAcceptsHttpSchemes(string $url, bool $shouldPass): void
    {
        $result = $this->sanitize(PullFileRequest::class, ['url' => $url, 'directory' => '/']);

        $this->assertSame($shouldPass, $result['errors'] === [], implode(' ', $result['errors']));
    }

    public static function pullSchemeProvider(): array
    {
        return [
            'https' => ['https://example.com/a.jar', true],
            'http' => ['http://example.com/a.jar', true],
            'file' => ['file:///etc/passwd', false],
            'gopher' => ['gopher://example.com/a', false],
        ];
    }

    /**
     * The same escape attempts must be refused wherever a path is accepted.
     */
    #[DataProvider('escapeAttemptProvider')]
    public function testEscapeAttemptsAreRefusedOnEveryRootRelativeOperation(string $path): void
    {
        foreach ([
            DeleteFileRequest::class => ['root' => '/plugins', 'files' => [$path]],
            CompressFilesRequest::class => ['root' => '/plugins', 'files' => [$path]],
            DecompressFilesRequest::class => ['root' => '/plugins', 'file' => $path],
            CreateFolderRequest::class => ['root' => '/plugins', 'name' => $path],
            RenameFileRequest::class => ['root' => '/plugins', 'files' => [['from' => 'a.txt', 'to' => $path]]],
        ] as $requestClass => $body) {
            $result = $this->sanitize($requestClass, $body);

            $this->assertNotSame(
                [],
                $result['errors'],
                sprintf('%s accepted %s', class_basename($requestClass), var_export($path, true))
            );
        }
    }

    public static function escapeAttemptProvider(): array
    {
        return [
            'parent traversal' => ['../escaped'],
            'nested traversal' => ['a/../../escaped'],
            'current directory' => ['./a.txt'],
            'absolute path' => ['/etc/passwd'],
            'drive letter' => ['C:/windows'],
            'null byte' => ["a\0.txt"],
            'double slash' => ['a//b.txt'],
            'empty' => [''],
        ];
    }

    /**
     * Guards the Request::merge() assumption the sanitizers rely on: a JSON body
     * and a query string must both be writable in place, or sanitized values
     * would never reach the controller.
     */
    public function testMergedValuesAreVisibleThroughBothInputSources(): void
    {
        $json = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"root":"/a"}');
        $json->merge(['root' => '/b']);
        $this->assertSame('/b', $json->input('root'));

        $query = Request::create('/x?directory=/a', 'GET');
        $query->merge(['directory' => '/b']);
        $this->assertSame('/b', $query->input('directory'));
        $this->assertSame('/b', $query->get('directory'));
    }
}
