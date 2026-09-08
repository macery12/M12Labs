<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionTrustedKey;
use Everest\Exceptions\DisplayException;
use Everest\Models\ExtensionSignatureAudit;
use Everest\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\ExtensionSignatureService;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;
use Everest\Services\Extensions\Manifest\ExtensionManifestCanonicalizer;

/**
 * Package signing.
 *
 * Keys are generated per test run: the real offline root must never exist on a
 * CI machine, and a test that needed it would be a reason to put it there.
 */
class ExtensionSignatureServiceTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    /** @var array{0: string, 1: string} root [public, secret] */
    private array $root;

    /** @var array{0: string, 1: string} release [public, secret] */
    private array $release;

    public function setUp(): void
    {
        parent::setUp();

        $rootPair = sodium_crypto_sign_keypair();
        $this->root = [sodium_crypto_sign_publickey($rootPair), sodium_crypto_sign_secretkey($rootPair)];

        $releasePair = sodium_crypto_sign_keypair();
        $this->release = [sodium_crypto_sign_publickey($releasePair), sodium_crypto_sign_secretkey($releasePair)];

        config()->set('extensions.signing.root_public_key', base64_encode($this->root[0]));
        config()->set('extensions.signing.root_fingerprint', hash('sha256', $this->root[0]));
        config()->set('extensions.signing.require_signature', true);
        config()->set('extensions.signing.allow_unsigned_local', false);
    }

    private function service(): ExtensionSignatureService
    {
        return app(ExtensionSignatureService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function keyRecord(bool $revoked = false, ?string $signWith = null): array
    {
        $record = [
            'keyId' => 'release-2026-09',
            'publicKey' => base64_encode($this->release[0]),
            'validFrom' => null,
            'validUntil' => null,
            'revoked' => $revoked,
        ];

        $message = implode("\n", [
            'm12labs-ext-key-v1',
            $record['keyId'],
            $record['publicKey'],
            '',
            '',
            $revoked ? 'revoked' : 'active',
        ]);

        $record['rootSignature'] = base64_encode(
            sodium_crypto_sign_detached($message, $signWith ?? $this->root[1])
        );

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawManifest(string $version = '1.0.0'): array
    {
        return [
            'manifestVersion' => 3,
            'package' => ['id' => 'signdemo', 'version' => $version, 'publisher' => 'm12labs'],
            'extension' => [
                'id' => 'signdemo',
                'name' => 'Sign Demo',
                'description' => 'Fixture.',
                'icon' => 'puzzle',
                'defaults' => ['enabled' => false],
            ],
            'compatiblePanelVersions' => ['>=Alpha 4.0 <Alpha 5.0'],
            'capabilities' => ['routes' => ['client' => true]],
            'files' => [[
                'path' => 'app/Extensions/Packages/signdemo/routes/client.php',
                'sha256' => str_repeat('0', 64),
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function sign(array $raw, string $archiveSha, ?string $signWith = null, string $keyId = 'release-2026-09'): array
    {
        // The publisher signs the manifest AS SHIPPED: the integrity block is
        // present, only its own signature is absent. Canonicalizing before
        // adding the block would sign different bytes than the panel verifies.
        $raw['integrity'] = [
            'archiveSha256' => $archiveSha,
            'signatureAlgorithm' => 'ed25519',
            'keyId' => $keyId,
        ];

        $canonicalizer = app(ExtensionManifestCanonicalizer::class);

        $message = $canonicalizer->signingMessage(
            $raw['extension']['id'],
            $raw['package']['version'],
            $canonicalizer->canonicalize($raw),
            $archiveSha
        );

        $raw['integrity']['signature'] = base64_encode(
            sodium_crypto_sign_detached($message, $signWith ?? $this->release[1])
        );

        return $raw;
    }

    private function parse(array $raw): \Everest\Services\Extensions\Manifest\ExtensionManifest
    {
        return app(ExtensionManifestParser::class)->parse($raw, 'signdemo');
    }

    /** The root vouches for a release key; nothing else can. */
    public function testOnlyRootSignedKeysAreAdmitted(): void
    {
        $strangerPair = sodium_crypto_sign_keypair();

        $result = $this->service()->syncRegistryKeys([
            $this->keyRecord(),
            $this->keyRecord(signWith: sodium_crypto_sign_secretkey($strangerPair)) + ['keyId' => 'forged'],
        ]);

        $this->assertSame(1, $result['admitted']);
        $this->assertSame(1, $result['rejected']);
        $this->assertTrue(ExtensionTrustedKey::query()->where('key_id', 'release-2026-09')->exists());
    }

    /**
     * A swapped root_public_key must fail the pinned fingerprint rather than
     * silently becoming a new root of trust.
     */
    public function testAMismatchedRootFingerprintAdmitsNothing(): void
    {
        config()->set('extensions.signing.root_fingerprint', hash('sha256', 'something else'));

        $result = $this->service()->syncRegistryKeys([$this->keyRecord()]);

        $this->assertSame(0, $result['admitted']);
        $this->assertFalse($this->service()->rootPinned());
    }

    public function testAValidlySignedArtifactVerifies(): void
    {
        $this->service()->syncRegistryKeys([$this->keyRecord()]);

        $archiveSha = hash('sha256', 'archive-bytes');
        $raw = $this->sign($this->rawManifest(), $archiveSha);

        $result = $this->service()->verify($this->parse($raw), $raw, $archiveSha);

        $this->assertSame('verified', $result['state']);
        $this->assertSame('release-2026-09', $result['keyId']);
        $this->assertSame(
            ExtensionSignatureAudit::VERDICT_VERIFIED,
            ExtensionSignatureAudit::query()->where('extension_id', 'signdemo')->value('verdict')
        );
    }

    /**
     * The signature covers the archive hash, so a signature lifted from one
     * release cannot be replayed onto different bytes.
     */
    public function testASignatureDoesNotTransferToAnotherArchive(): void
    {
        $this->service()->syncRegistryKeys([$this->keyRecord()]);

        $raw = $this->sign($this->rawManifest(), hash('sha256', 'archive-one'));

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not match its contents');

        $this->service()->verify($this->parse($raw), $raw, hash('sha256', 'archive-two'));
    }

    /** It also covers the manifest, so an edit after signing is caught. */
    public function testAManifestEditedAfterSigningIsRejected(): void
    {
        $this->service()->syncRegistryKeys([$this->keyRecord()]);

        $archiveSha = hash('sha256', 'archive-bytes');
        $raw = $this->sign($this->rawManifest(), $archiveSha);
        $raw['capabilities']['routes']['admin'] = true;

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not match its contents');

        $this->service()->verify($this->parse($raw), $raw, $archiveSha);
    }

    public function testARevokedKeyStopsVerifying(): void
    {
        $this->service()->syncRegistryKeys([$this->keyRecord()]);
        $this->service()->syncRegistryKeys([$this->keyRecord(revoked: true)]);

        $archiveSha = hash('sha256', 'archive-bytes');
        $raw = $this->sign($this->rawManifest(), $archiveSha);

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('revoked or outside its validity window');

        $this->service()->verify($this->parse($raw), $raw, $archiveSha);
    }

    public function testAnUnknownKeyIdIsRejected(): void
    {
        $archiveSha = hash('sha256', 'archive-bytes');
        $raw = $this->sign($this->rawManifest(), $archiveSha, keyId: 'never-seen');

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not trust');

        $this->service()->verify($this->parse($raw), $raw, $archiveSha);
    }

    /**
     * Rollback protection. An old release is still validly signed, so nothing
     * else in the chain objects to serving it — this is the only thing that
     * stops a downgrade to a version with a known flaw.
     */
    public function testAnOlderVersionIsRefusedAfterANewerOne(): void
    {
        $this->service()->syncRegistryKeys([$this->keyRecord()]);

        $newArchive = hash('sha256', 'archive-2');
        $new = $this->sign($this->rawManifest('2.0.0'), $newArchive);
        $this->service()->verify($this->parse($new), $new, $newArchive);

        $oldArchive = hash('sha256', 'archive-1');
        $old = $this->sign($this->rawManifest('1.0.0'), $oldArchive);

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('Refusing a rollback');

        $this->service()->verify($this->parse($old), $old, $oldArchive);
    }

    public function testAnUnsignedPackageIsRefusedWithoutAcknowledgement(): void
    {
        $this->service()->syncRegistryKeys([$this->keyRecord()]);

        $raw = $this->rawManifest();

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('not signed');

        $this->service()->verify($this->parse($raw), $raw, hash('sha256', 'archive-bytes'));
    }

    /**
     * An acknowledged unsigned install is admissible only in the shape that
     * cannot execute on core's behalf.
     */
    public function testAnAcknowledgedUnsignedInstallCannotDeclareHooksOrQueues(): void
    {
        config()->set('extensions.signing.allow_unsigned_local', true);
        $this->service()->syncRegistryKeys([$this->keyRecord()]);

        $raw = $this->rawManifest();
        $raw['capabilities']['queues'] = [['name' => 'sync']];
        $raw['files'][] = ['path' => 'app/Extensions/Packages/signdemo/Jobs/SyncJob.php', 'sha256' => str_repeat('0', 64)];

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('unverified package may not declare');

        $this->service()->verify($this->parse($raw), $raw, hash('sha256', 'archive-bytes'), acknowledgeUnsigned: true);
    }

    public function testAnAcknowledgedUnsignedInstallIsAllowedWithoutThoseCapabilities(): void
    {
        config()->set('extensions.signing.allow_unsigned_local', true);
        $this->service()->syncRegistryKeys([$this->keyRecord()]);

        $raw = $this->rawManifest();

        $result = $this->service()->verify(
            $this->parse($raw),
            $raw,
            hash('sha256', 'archive-bytes'),
            acknowledgeUnsigned: true
        );

        $this->assertSame('unsigned_acknowledged', $result['state']);
    }

    /**
     * With no root pinned there is no authority to check against, so signing is
     * not enforced. Requiring it would make the extension system unusable
     * rather than safer.
     */
    public function testWithNoRootPinnedNothingIsEnforced(): void
    {
        config()->set('extensions.signing.root_public_key', '');
        config()->set('extensions.signing.root_fingerprint', '');

        $this->assertFalse($this->service()->signingRequired());

        $raw = $this->rawManifest();
        $result = $this->service()->verify($this->parse($raw), $raw, hash('sha256', 'archive-bytes'));

        $this->assertSame('unsigned_acknowledged', $result['state']);
    }
}
