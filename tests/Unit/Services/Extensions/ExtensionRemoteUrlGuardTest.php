<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Services\Extensions\ExtensionRemoteUrlGuard;

class ExtensionRemoteUrlGuardTest extends TestCase
{
    #[DataProvider('unsafeUrlProvider')]
    public function testRejectsUnsafeRemoteUrls(string $url): void
    {
        $this->expectException(DisplayException::class);

        (new ExtensionRemoteUrlGuard())->assertSafeHttpsUrl($url, 'Archive URL');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeUrlProvider(): array
    {
        return [
            'plaintext' => ['http://example.com/archive.zip'],
            'credentials' => ['https://token@example.com/archive.zip'],
            'loopback-v4' => ['https://127.0.0.1/archive.zip'],
            'loopback-v6' => ['https://[::1]/archive.zip'],
            'private-v4' => ['https://10.0.0.4/archive.zip'],
            'link-local' => ['https://169.254.169.254/latest/meta-data'],
            'documentation-v4' => ['https://192.0.2.4/archive.zip'],
            'documentation-v6' => ['https://[2001:db8::4]/archive.zip'],
            'multicast-v4' => ['https://224.0.0.1/archive.zip'],
            'multicast-v6' => ['https://[ff02::1]/archive.zip'],
            'alternate-trailing-dot-host' => ['https://example.com./archive.zip'],
        ];
    }

    public function testAllowsPublicIpLiteralsWithoutAnotherDnsLookup(): void
    {
        $target = (new ExtensionRemoteUrlGuard())->assertSafeHttpsUrl(
            'https://[2606:4700:4700::1111]/extensions.json',
            'Repository manifest URL',
        );

        $this->assertSame('2606:4700:4700::1111', $target['host']);
        $this->assertSame(['2606:4700:4700::1111'], $target['addresses']);
        $this->assertTrue($target['literalIp']);
    }
}
