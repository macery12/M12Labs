<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionTrustedKey;
use Everest\Exceptions\DisplayException;
use Everest\Models\ExtensionSignatureAudit;
use Everest\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\ExtensionSignatureService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
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
    private function keyRecord(bool $revoked = false, ?string $signWith = null, ?string $publicKey = null): array
    {
        $publicKey ??= $this->release[0];
        $record = [
            'keyId' => 'release-2026-09',
            'publicKey' => base64_encode($publicKey),
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
     * @return array<string, mixed>
     */
    /**
     * A signature must not depend on the archive's own hash.
     *
     * It ships inside the archive, so a message covering the archive hash could
     * never be produced: writing the signature changes the hash it committed
     * to. This asserts the property a publisher relies on — sign once, and the
     * result verifies against whatever the resulting archive hashes to.
     */
    public function testSignatureIsIndependentOfTheArchiveHash(): void
    {
        $this->service()->syncRegistryKeys([$this->keyRecord()]);

        $raw = $this->sign($this->rawManifest(), hash('sha256', 'the-archive-before-signing'));

        $result = $this->service()->verify(
            $this->parse($raw),
            $raw,
            // What the archive actually hashes to once the signature is in it.
            hash('sha256', 'the-archive-after-signing'),
        );

        $this->assertSame('verified', $result['state']);
    }

    private function sign(array $raw, string $archiveSha, ?string $signWith = null, string $keyId = 'release-2026-09'): array
    {
        // The publisher signs the manifest AS SHIPPED: the integrity block is
        // present, only its own signature is absent. Canonicalizing before
        // adding the block would sign different bytes than the panel verifies.
        $raw['integrity'] = [
            'signatureAlgorithm' => 'ed25519',
            'keyId' => $keyId,
        ];

        $canonicalizer = app(ExtensionManifestCanonicalizer::class);

        $message = $canonicalizer->signingMessage(
            $raw['extension']['id'],
            $raw['package']['version'],
            $canonicalizer->canonicalize($raw),
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

    public function testAKeyIsBoundToTheRootThatAuthorizedIt(): void
    {
        $this->service()->syncRegistryKeys([$this->keyRecord()]);
        $this->assertTrue($this->service()->isReleaseKeyUsable('release-2026-09'));

        $newRootPair = sodium_crypto_sign_keypair();
        $newRootPublic = sodium_crypto_sign_publickey($newRootPair);
        $newRootSecret = sodium_crypto_sign_secretkey($newRootPair);
        config()->set('extensions.signing.root_public_key', base64_encode($newRootPublic));
        config()->set('extensions.signing.root_fingerprint', hash('sha256', $newRootPublic));

        $this->assertFalse($this->service()->isReleaseKeyUsable('release-2026-09'));
        $raw = $this->sign($this->rawManifest(), hash('sha256', 'root-rotation'));
        try {
            $this->service()->verify($this->parse($raw), $raw, hash('sha256', 'root-rotation'));
            $this->fail('A package signed by a key from the previous root should be rejected.');
        } catch (DisplayException $exception) {
            $this->assertStringContainsString('does not trust', $exception->getMessage());
        }

        $result = $this->service()->syncRegistryKeys([$this->keyRecord(signWith: $newRootSecret)]);

        $this->assertSame(1, $result['admitted']);
        $this->assertTrue($this->service()->isReleaseKeyUsable('release-2026-09'));
        $this->assertSame(
            hash('sha256', $newRootPublic),
            ExtensionTrustedKey::query()->where('key_id', 'release-2026-09')->value('root_fingerprint'),
        );
    }

    public function testANewRootCannotReplaceAnExistingKeyIdWithDifferentKeyBytes(): void
    {
        $this->service()->syncRegistryKeys([$this->keyRecord()]);
        $original = ExtensionTrustedKey::query()->where('key_id', 'release-2026-09')->firstOrFail();

        $newRootPair = sodium_crypto_sign_keypair();
        $newRootPublic = sodium_crypto_sign_publickey($newRootPair);
        $newRootSecret = sodium_crypto_sign_secretkey($newRootPair);
        $differentRelease = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        config()->set('extensions.signing.root_public_key', base64_encode($newRootPublic));
        config()->set('extensions.signing.root_fingerprint', hash('sha256', $newRootPublic));

        $result = $this->service()->syncRegistryKeys([
            $this->keyRecord(signWith: $newRootSecret, publicKey: $differentRelease),
        ]);

        $persisted = ExtensionTrustedKey::query()->where('key_id', 'release-2026-09')->firstOrFail();
        $this->assertSame(1, $result['rejected']);
        $this->assertSame($original->public_key, $persisted->public_key);
        $this->assertSame($original->root_fingerprint, $persisted->root_fingerprint);
        $this->assertFalse($this->service()->isReleaseKeyUsable('release-2026-09'));
    }

    public function testALegacyKeyWithoutARootBindingFailsClosedUntilRefresh(): void
    {
        $this->service()->syncRegistryKeys([$this->keyRecord()]);
        ExtensionTrustedKey::query()
            ->where('key_id', 'release-2026-09')
            ->update(['root_fingerprint' => null]);

        $this->assertFalse($this->service()->isReleaseKeyUsable('release-2026-09'));

        $this->service()->syncRegistryKeys([$this->keyRecord()]);

        $this->assertTrue($this->service()->isReleaseKeyUsable('release-2026-09'));
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
     * A signature lifted onto a different package is rejected, because the
     * manifest names the extension and version it was issued for.
     *
     * Contents are bound separately: the signed manifest carries a sha256 per
     * file, and the installer copies only files the manifest lists, verifying
     * each (see ExtensionInstallManifestTest). That is what stops a valid
     * signature being wrapped around different code — not the archive hash,
     * which the signature cannot cover.
     */
    public function testASignatureDoesNotTransferToAnotherPackage(): void
    {
        $this->service()->syncRegistryKeys([$this->keyRecord()]);

        $raw = $this->sign($this->rawManifest(), hash('sha256', 'archive-one'));
        $raw['package']['version'] = '9.9.9';
        $raw['extension']['defaults'] = ['enabled' => true];

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not match its contents');

        $this->service()->verify($this->parse($raw), $raw, hash('sha256', 'archive-one'));
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

    public function testReplayingAnOlderActiveRecordCannotUndoRevocation(): void
    {
        $active = $this->keyRecord();
        $this->service()->syncRegistryKeys([$active]);
        $this->service()->syncRegistryKeys([$this->keyRecord(revoked: true)]);
        $result = $this->service()->syncRegistryKeys([$active]);

        $key = ExtensionTrustedKey::query()->where('key_id', 'release-2026-09')->firstOrFail();

        $this->assertNotNull($key->revoked_at);
        $this->assertSame(1, $result['revoked']);
        $this->assertSame(0, $result['admitted']);
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

    public function testAllExecutableAndPrivilegedSurfacesAreRestrictedWhenUnverified(): void
    {
        $capabilities = new ExtensionCapabilitySet(
            clientRoutes: true,
            adminRoutes: true,
            serverPages: [new \stdClass()],
            adminPages: [new \stdClass()],
            adminPermissions: [new \stdClass()],
            migrations: true,
            hooks: [new \stdClass()],
            queues: [new \stdClass()],
            schedule: true,
            commands: ['p:ext:signdemo:run'],
        );

        $this->assertSame([
            'routes.client',
            'routes.admin',
            'pages.server',
            'pages.admin',
            'database.migrations',
            'schedule',
            'commands',
            'hooks',
            'queues',
            'permissions.admin',
        ], $this->service()->restrictedCapabilitiesForUnverified($capabilities));
    }

    public function testAnAcknowledgedUnsignedInstallIsAllowedOnlyWhenInert(): void
    {
        config()->set('extensions.signing.allow_unsigned_local', true);
        $this->service()->syncRegistryKeys([$this->keyRecord()]);

        $raw = $this->rawManifest();
        $raw['capabilities'] = [];
        $raw['files'] = [[
            'path' => 'app/Extensions/Packages/signdemo/README.md',
            'sha256' => str_repeat('0', 64),
        ]];

        $result = $this->service()->verify(
            $this->parse($raw),
            $raw,
            hash('sha256', 'archive-bytes'),
            acknowledgeUnsigned: true
        );

        $this->assertSame('unsigned_acknowledged', $result['state']);
    }

    public function testRequiredSigningFailsClosedWithNoRootPin(): void
    {
        config()->set('extensions.signing.root_public_key', '');
        config()->set('extensions.signing.root_fingerprint', '');

        $this->assertTrue($this->service()->signingRequired());

        $raw = $this->rawManifest();

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('Signature verification is required');
        $this->service()->verify($this->parse($raw), $raw, hash('sha256', 'archive-bytes'));
    }

    public function testRequiredSigningFailsClosedWithMalformedRootPin(): void
    {
        config()->set('extensions.signing.root_public_key', 'not-base64');
        config()->set('extensions.signing.root_fingerprint', str_repeat('0', 64));

        $raw = $this->rawManifest();

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('missing, malformed, or do not match');
        $this->service()->verify($this->parse($raw), $raw, hash('sha256', 'archive-bytes'));
    }

    public function testSigningCanBeExplicitlyDisabledForLocalDevelopment(): void
    {
        config()->set('extensions.signing.require_signature', false);
        config()->set('extensions.signing.root_public_key', '');
        config()->set('extensions.signing.root_fingerprint', '');

        $raw = $this->rawManifest();
        $result = $this->service()->verify($this->parse($raw), $raw, hash('sha256', 'archive-bytes'));

        $this->assertSame('unsigned_acknowledged', $result['state']);
    }
}
