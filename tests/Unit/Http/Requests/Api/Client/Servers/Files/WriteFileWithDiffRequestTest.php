<?php

namespace Everest\Tests\Unit\Http\Requests\Api\Client\Servers\Files;

use Everest\Tests\TestCase;
use Illuminate\Http\Request;
use Everest\Models\Permission;
use Illuminate\Pipeline\Pipeline;
use Everest\Http\Middleware\TrimStrings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Everest\Http\Requests\Api\Client\Servers\Files\WriteFileWithDiffRequest;

class WriteFileWithDiffRequestTest extends TestCase
{
    public function testExistingCreatePermissionBehaviorRemainsUnchanged(): void
    {
        $this->assertSame(
            Permission::ACTION_FILE_CREATE,
            (new WriteFileWithDiffRequest())->permission()
        );
    }

    public function testDecodedContentFieldsHaveStrictByteCaps(): void
    {
        $request = new WriteFileWithDiffRequest();
        $oversized = str_repeat('x', WriteFileWithDiffRequest::MAX_CONTENT_BYTES + 1);

        $validator = Validator::make([
            'file' => '/server.properties',
            'content' => $oversized,
            'original_content' => '',
        ], $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content', $validator->errors()->toArray());
    }

    public function testBothContentFieldsHaveStrictLineCaps(): void
    {
        $request = new WriteFileWithDiffRequest();
        $tooManyLines = str_repeat("\n", WriteFileWithDiffRequest::MAX_CONTENT_LINES);

        $validator = Validator::make([
            'file' => '/server.properties',
            'content' => $tooManyLines,
            'original_content' => $tooManyLines,
        ], $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content', $validator->errors()->toArray());
        $this->assertArrayHasKey('original_content', $validator->errors()->toArray());
    }

    public function testBoundarySizedContentPassesFieldValidation(): void
    {
        $request = new WriteFileWithDiffRequest();
        $content = str_repeat('x', WriteFileWithDiffRequest::MAX_CONTENT_BYTES);

        $validator = Validator::make([
            'file' => '/server.properties',
            'content' => $content,
            'original_content' => '',
        ], $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->passes(), implode(' ', $validator->errors()->all()));
    }

    /**
     * The compare-and-swap token is mandatory, in one form or the other. It is
     * checked on key presence rather than with required_without, because "" is
     * the legitimate original for an existing empty file.
     *
     * @param array<string, string> $body
     */
    #[DataProvider('casTokenProvider')]
    public function testExactlyOneCompareAndSwapTokenIsRequired(array $body, bool $shouldPass): void
    {
        $request = new WriteFileWithDiffRequest();
        $validator = Validator::make($body + ['file' => '/server.properties', 'content' => 'after'], $request->rules());
        $request->withValidator($validator);

        $this->assertSame($shouldPass, $validator->passes(), implode(' ', $validator->errors()->all()));
    }

    public static function casTokenProvider(): array
    {
        $hash = str_repeat('a', 64);

        return [
            'hash only' => [['original_hash' => $hash], true],
            'content only' => [['original_content' => 'before'], true],
            'empty content is a valid original' => [['original_content' => ''], true],
            'neither' => [[], false],
            'both' => [['original_hash' => $hash, 'original_content' => 'before'], false],
            'hash too short' => [['original_hash' => str_repeat('a', 63)], false],
            'hash not hex' => [['original_hash' => str_repeat('z', 64)], false],
            'hash uppercase' => [['original_hash' => str_repeat('A', 64)], false],
        ];
    }

    /** Both token forms must resolve to the same digest the controller compares. */
    public function testOriginalHashResolvesFromEitherTokenForm(): void
    {
        $expected = hash('sha256', "b: 1\n");

        $fromContent = $this->prepared([
            'file' => '/a.yml',
            'content' => 'x',
            'original_content' => "b: 1\n",
        ]);
        $this->assertSame($expected, $fromContent->originalHash());

        $fromHash = $this->prepared([
            'file' => '/a.yml',
            'content' => 'x',
            'original_hash' => $expected,
        ]);
        $this->assertSame($expected, $fromHash->originalHash());
    }

    /**
     * The point of accepting a hash: the file no longer has to fit in the body
     * twice, so a file the editor can open is one it can save.
     *
     * Note the payload. A ceiling-sized file of plain ASCII already fits twice
     * (2 x 4 MiB is under the 10 MiB body cap), so the gap only bites once JSON
     * escaping inflates the content — which it does for exactly the files people
     * edit at that size: JSON and data files dense with quotes and backslashes,
     * each of which encodes to two bytes.
     */
    public function testAnEscapeHeavyCeilingSizedFileOnlyFitsWhenSendingAHash(): void
    {
        $content = str_repeat('"', WriteFileWithDiffRequest::MAX_CONTENT_BYTES);

        $withHash = strlen((string) json_encode([
            'file' => '/loot_tables.json',
            'content' => $content,
            'original_hash' => hash('sha256', $content),
        ]));
        $withContent = strlen((string) json_encode([
            'file' => '/loot_tables.json',
            'content' => $content,
            'original_content' => $content,
        ]));

        $this->assertLessThan(WriteFileWithDiffRequest::MAX_REQUEST_BYTES, $withHash);
        $this->assertGreaterThan(WriteFileWithDiffRequest::MAX_REQUEST_BYTES, $withContent);
    }

    /** A plain-ASCII file at the ceiling fits either way — the honest baseline. */
    public function testAPlainCeilingSizedFileFitsEitherWay(): void
    {
        $content = str_repeat('x', WriteFileWithDiffRequest::MAX_CONTENT_BYTES);

        $this->assertLessThan(
            WriteFileWithDiffRequest::MAX_REQUEST_BYTES,
            strlen((string) json_encode([
                'file' => '/big.txt',
                'content' => $content,
                'original_content' => $content,
            ]))
        );
    }

    public function testRawJsonBodyHasASeparateHardCap(): void
    {
        $request = TestableWriteFileWithDiffRequest::createFromBase(
            Request::create(
                '/write-with-diff',
                'POST',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                str_repeat('x', WriteFileWithDiffRequest::MAX_REQUEST_BYTES + 1)
            )
        );
        $request->setContainer($this->app);

        $this->expectException(ValidationException::class);
        $request->runPrepareForValidation();
    }

    /**
     * File contents are bytes, not form input. The global TrimStrings and
     * ConvertEmptyStringsToNull middleware used to reach them, which silently
     * corrupted every save: the trailing newline and leading indentation were
     * stripped from `content`, the same trim applied to `original_content`
     * broke the compare-and-swap against the live file (a 409 on any file
     * ending in a newline), and an empty file arrived as null.
     *
     * @param array{content: string, original_content: string} $body
     */
    #[DataProvider('untransformedContentProvider')]
    public function testGlobalInputTransformersDoNotReachFileContents(array $body): void
    {
        $request = $this->prepared($body + ['file' => '/server.properties']);

        $this->assertSame($body['content'], $request->input('content'));
        $this->assertSame($body['original_content'], $request->input('original_content'));

        $base = new WriteFileWithDiffRequest();
        $validator = Validator::make($request->all(), $base->rules());
        $base->withValidator($validator);
        $this->assertTrue($validator->passes(), implode(' ', $validator->errors()->all()));
    }

    public static function untransformedContentProvider(): array
    {
        return [
            'trailing newline' => [['content' => "b: 2\n", 'original_content' => "b: 1\n"]],
            'leading indentation' => [['content' => '  indented', 'original_content' => '  old']],
            'clearing a file' => [['content' => '', 'original_content' => "x\n"]],
            'whitespace-only file' => [['content' => "\n\n", 'original_content' => ' ']],
            'crlf line endings' => [['content' => "a\r\nb\r\n", 'original_content' => "a\r\n"]],
        ];
    }

    public function testNonStringContentIsNotRestoredOverTheValidator(): void
    {
        // The restore must not smuggle a non-string past the `string` rule.
        $request = $this->prepared(['file' => '/a.txt', 'content' => ['nope'], 'original_content' => 5]);

        $base = new WriteFileWithDiffRequest();
        $validator = Validator::make($request->all(), $base->rules());

        $this->assertTrue($validator->fails());
    }

    /** Push a body through the global transformers, then prepare the request. */
    private function prepared(array $body): TestableWriteFileWithDiffRequest
    {
        $base = Request::create(
            '/api/client/servers/abc/files/write-with-diff',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($body)
        );

        $transformed = (new Pipeline($this->app))
            ->send($base)
            ->through([TrimStrings::class, ConvertEmptyStringsToNull::class])
            ->then(fn (Request $request) => $request);

        $request = TestableWriteFileWithDiffRequest::createFromBase($transformed);
        $request->setContainer($this->app);
        $request->runPrepareForValidation();

        return $request;
    }

    public function testAuthorizationRunsBeforeOversizedBodyInspection(): void
    {
        $request = UnauthorizedWriteFileWithDiffRequest::createFromBase(
            Request::create(
                '/write-with-diff',
                'POST',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                str_repeat('x', WriteFileWithDiffRequest::MAX_REQUEST_BYTES + 1)
            )
        );
        $request->setContainer($this->app);

        $this->expectException(AuthorizationException::class);
        $request->runPrepareForValidation();
    }
}

class TestableWriteFileWithDiffRequest extends WriteFileWithDiffRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function runPrepareForValidation(): void
    {
        parent::prepareForValidation();
    }
}

class UnauthorizedWriteFileWithDiffRequest extends TestableWriteFileWithDiffRequest
{
    public function authorize(): bool
    {
        return false;
    }
}
