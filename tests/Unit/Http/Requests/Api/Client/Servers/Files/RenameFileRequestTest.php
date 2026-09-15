<?php

namespace Everest\Tests\Unit\Http\Requests\Api\Client\Servers\Files;

use Everest\Tests\TestCase;
use Everest\Models\Permission;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Http\Requests\Api\Client\Servers\Files\RenameFileRequest;

class RenameFileRequestTest extends TestCase
{
    /**
     * Run the request's own sanitizer over a payload and report what the
     * controller would hand to the daemon.
     *
     * @return array{errors: list<string>, root: mixed, files: mixed}
     */
    private function sanitize(string $root, array $files): array
    {
        $request = RenameFileRequest::create(
            '/api/client/servers/abc/files/rename',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['root' => $root, 'files' => $files])
        );
        $request->setContainer($this->app);

        $validator = $this->app->make(Factory::class)->make($request->all(), $request->rules());
        $request->withValidator($validator);

        return [
            'errors' => $validator->fails() ? $validator->errors()->all() : [],
            'root' => $request->input('root'),
            'files' => $request->input('files'),
        ];
    }

    public function testPermissionIsUnchanged(): void
    {
        $this->assertSame(Permission::ACTION_FILE_UPDATE, (new RenameFileRequest())->permission());
    }

    /**
     * The daemon joins `from`/`to` onto `root` itself. Sending back a
     * root-prefixed path made it resolve /plugins/plugins/a.txt, match nothing,
     * and answer 200 having renamed nothing at all.
     */
    #[DataProvider('rootProvider')]
    public function testTargetsStayRelativeToTheRoot(string $root, string $expectedRoot): void
    {
        $result = $this->sanitize($root, [['from' => 'a.txt', 'to' => 'b.txt']]);

        $this->assertSame([], $result['errors']);
        $this->assertSame($expectedRoot, $result['root']);
        $this->assertSame([['from' => 'a.txt', 'to' => 'b.txt']], $result['files']);
    }

    public static function rootProvider(): array
    {
        return [
            'server root' => ['/', '/'],
            'subdirectory' => ['/plugins', '/plugins'],
            'nested subdirectory' => ['/plugins/conf', '/plugins/conf'],
            'unrooted subdirectory' => ['plugins', '/plugins'],
        ];
    }

    public function testMovingIntoASubdirectoryKeepsTheRelativeRemainder(): void
    {
        $result = $this->sanitize('/plugins', [['from' => 'a.txt', 'to' => 'archive/a.txt']]);

        $this->assertSame([], $result['errors']);
        $this->assertSame([['from' => 'a.txt', 'to' => 'archive/a.txt']], $result['files']);
    }

    /**
     * The old allowlist (/^[A-Za-z0-9._ -]+$/) rejected ordinary file names, and
     * since it also ran over `root` it broke every rename inside a folder whose
     * own name held one of those characters.
     */
    #[DataProvider('ordinaryNameProvider')]
    public function testOrdinaryNamesAreAccepted(string $root, string $from, string $to): void
    {
        $result = $this->sanitize($root, [['from' => $from, 'to' => $to]]);

        $this->assertSame([], $result['errors']);
        $this->assertSame([['from' => $from, 'to' => $to]], $result['files']);
    }

    public static function ordinaryNameProvider(): array
    {
        return [
            'parentheses' => ['/', 'map (1).zip', 'map2.zip'],
            'accented' => ['/', 'café.txt', 'cafe.txt'],
            'plus sign' => ['/', 'a+b.cfg', 'c.cfg'],
            'hash and comma' => ['/', 'server#1,old.jar', 'server.jar'],
            'cjk' => ['/', '配置.yml', 'config.yml'],
            'root with parentheses' => ['/plugins (old)', 'a.txt', 'b.txt'],
            'directory named zero' => ['/0', 'a.txt', 'b.txt'],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function testEscapeAttemptsAreStillRejected(string $root, string $to): void
    {
        $result = $this->sanitize($root, [['from' => 'a.txt', 'to' => $to]]);

        $this->assertNotSame([], $result['errors'], 'expected the sanitizer to reject ' . var_export($to, true));
    }

    public static function rejectedProvider(): array
    {
        return [
            'parent traversal' => ['/plugins', '../escaped.txt'],
            'nested traversal' => ['/plugins', 'a/../../escaped.txt'],
            'current directory' => ['/plugins', './a.txt'],
            'absolute path' => ['/plugins', '/etc/passwd'],
            'drive letter' => ['/plugins', 'C:/windows'],
            'null byte' => ['/plugins', "a\0.txt"],
            'newline' => ['/plugins', "a\n.txt"],
            'empty name' => ['/plugins', ''],
            'double slash' => ['/plugins', 'a//b.txt'],
        ];
    }

    public function testATraversingRootIsRejected(): void
    {
        $result = $this->sanitize('/plugins/../..', [['from' => 'a.txt', 'to' => 'b.txt']]);

        $this->assertNotSame([], $result['errors']);
    }

    public function testRejectedTargetsAreNeverPassedThrough(): void
    {
        $result = $this->sanitize('/plugins', [
            ['from' => 'a.txt', 'to' => 'b.txt'],
            ['from' => 'c.txt', 'to' => '../escaped.txt'],
        ]);

        $this->assertNotSame([], $result['errors']);
        // The merge is skipped wholesale on failure, so nothing sanitized leaks
        // to the daemon — and the request fails before the controller runs.
        $this->assertSame(
            [['from' => 'a.txt', 'to' => 'b.txt'], ['from' => 'c.txt', 'to' => '../escaped.txt']],
            $result['files']
        );
    }

    public function testValidatorIsAnIlluminateValidator(): void
    {
        // Guards the loose `withValidator($validator)` signature against drift.
        $validator = $this->app->make(Factory::class)->make([], []);
        $this->assertInstanceOf(Validator::class, $validator);
    }
}
