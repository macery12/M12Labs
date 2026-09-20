<?php

namespace Everest\Tests\Integration;

use Everest\Tests\TestCase;
use Everest\Events\ActivityLogged;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Event;
use Everest\Models\ExtensionTrustedKey;
use Everest\Tests\Assertions\AssertsActivityLogged;
use Everest\Tests\Traits\Integration\CreatesTestModels;
use Everest\Services\Extensions\ExtensionSignatureService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\ExtensionManifestCanonicalizer;

abstract class IntegrationTestCase extends TestCase
{
    use CreatesTestModels;
    use AssertsActivityLogged;

    protected $defaultHeaders = [
        'Accept' => 'application/json',
    ];

    /** @var array<string, string> key id => raw Ed25519 secret key */
    private array $extensionSigningSecrets = [];

    private ?string $extensionFixtureBasePath = null;

    private ?string $originalBasePath = null;

    public function setUp(): void
    {
        parent::setUp();

        Event::fake(ActivityLogged::class);
    }

    protected function tearDown(): void
    {
        if ($this->extensionFixtureBasePath !== null) {
            File::deleteDirectory($this->extensionFixtureBasePath);
        }

        if ($this->originalBasePath !== null) {
            $this->app->setBasePath($this->originalBasePath);
        }

        $this->extensionFixtureBasePath = null;
        $this->originalBasePath = null;
        $this->extensionSigningSecrets = [];

        parent::tearDown();
    }

    protected function trustExtensionSigningKey(string $keyId = 'integration-test-release'): ExtensionTrustedKey
    {
        $rootFingerprint = app(ExtensionSignatureService::class)->currentRootFingerprint();
        if ($rootFingerprint === null) {
            throw new \RuntimeException('Extension integration fixtures require a valid signing root.');
        }

        if (isset($this->extensionSigningSecrets[$keyId])) {
            return ExtensionTrustedKey::query()->where('key_id', $keyId)->firstOrFail();
        }

        if (ExtensionTrustedKey::query()->where('key_id', $keyId)->exists()) {
            throw new \RuntimeException(sprintf('The signing secret for existing integration key [%s] is unavailable.', $keyId));
        }

        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $this->extensionSigningSecrets[$keyId] = sodium_crypto_sign_secretkey($keyPair);

        return ExtensionTrustedKey::query()->create([
            'key_id' => $keyId,
            'public_key' => base64_encode($publicKey),
            'fingerprint' => hash('sha256', $publicKey),
            'root_fingerprint' => $rootFingerprint,
        ]);
    }

    /**
     * Build the database attributes of a genuine signed runtime package and
     * place its declared fixture files under the production package root.
     *
     * @param array<string, string>|null $files full package-relative path => contents
     *
     * @return array<string, mixed>
     */
    protected function signedRuntimePackageAttributes(
        string $id,
        ExtensionCapabilitySet $capabilities,
        ?array $files = null,
        string $version = '1.0.0',
    ): array {
        $keyId = 'integration-test-release-' . $id;
        $key = $this->trustExtensionSigningKey($keyId);
        $secret = $this->extensionSigningSecrets[$keyId] ?? null;
        if (!is_string($secret)) {
            throw new \RuntimeException('The integration release signing secret is unavailable.');
        }

        if ($this->extensionFixtureBasePath === null) {
            $this->originalBasePath = base_path();
            $this->extensionFixtureBasePath = sys_get_temp_dir() . '/m12labs-extension-fixture-' . bin2hex(random_bytes(12));
            File::ensureDirectoryExists($this->extensionFixtureBasePath);
            $this->app->setBasePath($this->extensionFixtureBasePath);
        }

        $packageRoot = base_path('app/Extensions/Packages/' . $id);
        if (file_exists($packageRoot)) {
            throw new \RuntimeException(sprintf('Refusing to replace existing extension fixture directory [%s].', $packageRoot));
        }

        $files ??= [
            sprintf('app/Extensions/Packages/%s/Support/IntegrityMarker.php', $id) => "<?php\n",
        ];

        $manifestFiles = [];
        foreach ($files as $path => $contents) {
            $absolute = base_path($path);
            File::ensureDirectoryExists(dirname($absolute));
            File::put($absolute, $contents);
            $manifestFiles[] = ['path' => $path, 'sha256' => hash('sha256', $contents)];
        }
        $manifest = [
            'manifestVersion' => 3,
            'package' => ['id' => $id, 'version' => $version, 'publisher' => 'integration-tests'],
            'extension' => [
                'id' => $id,
                'name' => $id,
                'description' => 'Runtime integrity fixture.',
                'icon' => 'puzzle',
                'defaults' => ['enabled' => false],
            ],
            'compatiblePanelVersions' => ['>=Alpha 4.0 <Alpha 5.0'],
            'capabilities' => $capabilities->jsonSerialize(),
            'files' => $manifestFiles,
            'integrity' => [
                'signatureAlgorithm' => 'ed25519',
                'keyId' => $key->key_id,
            ],
        ];

        $canonicalizer = app(ExtensionManifestCanonicalizer::class);
        $unsignedJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $canonical = $canonicalizer->canonicalizeJson($unsignedJson);
        $message = $canonicalizer->signingMessage($id, $version, $canonical);
        $manifest['integrity']['signature'] = base64_encode(sodium_crypto_sign_detached($message, $secret));
        $signedJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return [
            'extension_id' => $id,
            'package_id' => $id,
            'name' => $id,
            'icon' => 'puzzle',
            'installed_version' => $version,
            'manifest' => $manifest,
            'signed_manifest' => $signedJson,
            'manifest_hash' => hash('sha256', $canonical),
            'manifest_version' => 3,
            'signature_state' => 'verified',
            'signature_key_id' => $key->key_id,
            'signature_verified_at' => now(),
            'capabilities' => $capabilities->jsonSerialize(),
            'capability_hash' => $capabilities->hash(),
            'approved_capability_hash' => $capabilities->hash(),
            'package_checksum' => hash('sha256', 'integration-package:' . $id . ':' . $version),
        ];
    }
}
