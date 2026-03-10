<?php

namespace Everest\Tests\Unit\Services\Security;

use Illuminate\Encryption\MissingAppKeyException;
use Everest\Services\Security\SecretEncryptionService;
use Everest\Tests\TestCase;

class SecretEncryptionServiceTest extends TestCase
{
    public function testEncryptAndDecryptRoundTrip(): void
    {
        $service = new SecretEncryptionService();

        $encrypted = $service->encryptForStorage('super-secret');

        $this->assertNotSame('super-secret', $encrypted);
        $this->assertSame('super-secret', $service->decryptFromStorage($encrypted));
    }

    public function testDecryptsPlaintextValuesWithoutError(): void
    {
        $service = new SecretEncryptionService();

        $this->assertSame('plain-value', $service->decryptFromStorage('plain-value'));
    }

    public function testGracefullyHandlesMissingAppKeyOnDecrypt(): void
    {
        $service = new SecretEncryptionService();
        $originalKey = config('app.key');

        config()->set('app.key', null);

        $this->assertNull($service->decryptFromStorage('anything'));

        config()->set('app.key', $originalKey);
    }

    public function testEncryptThrowsWhenAppKeyMissing(): void
    {
        $this->expectException(MissingAppKeyException::class);

        $service = new SecretEncryptionService();
        $originalKey = config('app.key');

        config()->set('app.key', null);

        try {
            $service->encryptForStorage('value');
        } finally {
            config()->set('app.key', $originalKey);
        }
    }
}
