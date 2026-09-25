<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionRemoteUrlGuard;
use Everest\Services\Extensions\ExtensionRemoteResourceService;

class ExtensionRemoteResourceServiceTest extends TestCase
{
    public function testRejectsAnOversizedStreamAndDeletesThePartialDownload(): void
    {
        Http::fake([
            'https://203.0.113.10/archive' => Http::response(str_repeat('x', 17), 200),
        ]);

        $guard = \Mockery::mock(ExtensionRemoteUrlGuard::class);
        $guard->shouldReceive('assertSafeHttpsUrl')->once()->andReturn([
            'host' => '203.0.113.10',
            'port' => 443,
            'addresses' => ['203.0.113.10'],
            'literalIp' => true,
        ]);

        $destination = sys_get_temp_dir() . '/extension-download-' . bin2hex(random_bytes(6));

        try {
            (new ExtensionRemoteResourceService($guard))->download(
                'https://203.0.113.10/archive',
                $destination,
                16,
                30,
                10,
                0,
                'extension archive',
            );
            $this->fail('The oversized download should have been rejected.');
        } catch (DisplayException $exception) {
            $this->assertStringContainsString('larger than the permitted 16 bytes', $exception->getMessage());
            $this->assertFileDoesNotExist($destination);
        }
    }

    public function testValidatesEveryRedirectDestinationBeforeFetchingIt(): void
    {
        Http::fake([
            'https://198.51.100.20/archive' => Http::response('', 302, [
                'Location' => 'https://127.0.0.1/internal',
            ]),
        ]);

        $guard = \Mockery::mock(ExtensionRemoteUrlGuard::class);
        $guard->shouldReceive('assertSafeHttpsUrl')->once()->ordered()->andReturn([
            'host' => '198.51.100.20',
            'port' => 443,
            'addresses' => ['198.51.100.20'],
            'literalIp' => true,
        ]);
        $guard->shouldReceive('assertSafeHttpsUrl')->once()->ordered()->andThrow(
            new DisplayException('Redirect URL must resolve only to public network addresses.'),
        );

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('public network addresses');

        (new ExtensionRemoteResourceService($guard))->getContents(
            'https://198.51.100.20/archive',
            1024,
            30,
            10,
            3,
            'extension archive',
        );

        Http::assertSentCount(1);
    }

    public function testRejectsAnOversizedDeclaredContentLengthBeforeWriting(): void
    {
        Http::fake([
            'https://203.0.113.11/archive' => Http::response('small', 200, [
                'Content-Length' => '2048',
            ]),
        ]);

        $guard = \Mockery::mock(ExtensionRemoteUrlGuard::class);
        $guard->shouldReceive('assertSafeHttpsUrl')->once()->andReturn([
            'host' => '203.0.113.11',
            'port' => 443,
            'addresses' => ['203.0.113.11'],
            'literalIp' => true,
        ]);

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('larger than the permitted 1024 bytes');

        (new ExtensionRemoteResourceService($guard))->getContents(
            'https://203.0.113.11/archive',
            1024,
            30,
            10,
            0,
            'extension archive',
        );
    }
}
