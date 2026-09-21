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

    public function testDropsAddressFamiliesTheHostHasNoRouteFor(): void
    {
        $guard = $this->guardResolving(
            ['185.199.108.133', '2606:50c0:8000::154'],
            routable: ['185.199.108.133'],
        );

        $target = $guard->assertSafeHttpsUrl('https://example.com/registry.json', 'Repository manifest URL');

        // Pinning the resolver's answer is what makes the safety check and the
        // connection agree, but it also means nothing else is left to fall back
        // to IPv4 when the machine has AAAA records and no IPv6 route.
        $this->assertSame(['185.199.108.133'], $target['addresses']);
    }

    public function testKeepsEveryAddressWhenNoneIsRoutable(): void
    {
        $guard = $this->guardResolving(
            ['185.199.108.133', '2606:50c0:8000::154'],
            routable: [],
        );

        $target = $guard->assertSafeHttpsUrl('https://example.com/registry.json', 'Repository manifest URL');

        // A host that is genuinely unreachable should fail as a connection
        // error, which says something true, rather than as "could not be
        // resolved", which does not.
        $this->assertSame(['185.199.108.133', '2606:50c0:8000::154'], $target['addresses']);
    }

    /**
     * @param array<int, string> $addresses
     * @param array<int, string> $routable
     */
    private function guardResolving(array $addresses, array $routable): ExtensionRemoteUrlGuard
    {
        return new class ($addresses, $routable) extends ExtensionRemoteUrlGuard {
            /**
             * @param array<int, string> $addresses
             * @param array<int, string> $routable
             */
            public function __construct(private array $addresses, private array $routable)
            {
            }

            protected function resolveHostAddresses(string $host): array
            {
                return $this->addresses;
            }

            protected function hasRouteTo(string $address): bool
            {
                return in_array($address, $this->routable, true);
            }
        };
    }
}
