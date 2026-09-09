<?php

namespace Everest\Tests\Integration;

use Everest\Tests\TestCase;
use Everest\Events\ActivityLogged;
use Illuminate\Support\Facades\Event;
use Everest\Models\ExtensionTrustedKey;
use Everest\Tests\Assertions\AssertsActivityLogged;
use Everest\Tests\Traits\Integration\CreatesTestModels;
use Everest\Services\Extensions\ExtensionSignatureService;

abstract class IntegrationTestCase extends TestCase
{
    use CreatesTestModels;
    use AssertsActivityLogged;

    protected $defaultHeaders = [
        'Accept' => 'application/json',
    ];

    public function setUp(): void
    {
        parent::setUp();

        Event::fake(ActivityLogged::class);
    }

    protected function trustExtensionSigningKey(string $keyId = 'integration-test-release'): ExtensionTrustedKey
    {
        $rootFingerprint = app(ExtensionSignatureService::class)->currentRootFingerprint();
        if ($rootFingerprint === null) {
            throw new \RuntimeException('Extension integration fixtures require a valid signing root.');
        }

        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);

        return ExtensionTrustedKey::query()->firstOrCreate(
            ['key_id' => $keyId],
            [
                'public_key' => base64_encode($publicKey),
                'fingerprint' => hash('sha256', $publicKey),
                'root_fingerprint' => $rootFingerprint,
            ],
        );
    }
}
