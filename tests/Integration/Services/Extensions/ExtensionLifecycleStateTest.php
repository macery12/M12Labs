<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Extensions\ExtensionRuntimeGate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;

/**
 * The lifecycle state is the authority on whether a package may load code.
 *
 * Alpha 4.0 accepts manifest v3 only, so packages built for v1/v2 are
 * quarantined as "unsupported". These tests pin the two properties that make
 * the quarantine real rather than advisory: the runtime gate refuses to load a
 * quarantined package even when its enabled flag says otherwise, and the API
 * refuses to set that flag in the first place.
 */
class ExtensionLifecycleStateTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function setUp(): void
    {
        parent::setUp();

        ExtensionRuntimeGate::flush();
        config()->set('modules.extensions.enabled', true);
    }

    public function tearDown(): void
    {
        ExtensionRuntimeGate::flush();

        parent::tearDown();
    }

    private function package(string $id, string $state, int $manifestVersion, bool $enabled): ExtensionPackage
    {
        // A realistic row: the runtime plan rehydrates the stored capability
        // projection and checks it against capability_hash, so a fixture
        // without one is (correctly) treated as inconsistent and never loads.
        $capabilities = new ExtensionCapabilitySet(clientRoutes: true);
        $attributes = $manifestVersion === 3
            ? $this->signedRuntimePackageAttributes(
                $id,
                $capabilities,
                [sprintf('app/Extensions/Packages/%s/routes/client.php', $id) => "<?php\n"],
            )
            : [
                'extension_id' => $id,
                'package_id' => $id,
                'name' => $id,
                'icon' => 'puzzle',
                'installed_version' => '1.0.0',
                'manifest' => ['manifestVersion' => $manifestVersion, 'extension' => ['id' => $id]],
                'manifest_version' => $manifestVersion,
                'signature_state' => 'unsigned_acknowledged',
                'capabilities' => $capabilities->jsonSerialize(),
                'capability_hash' => $capabilities->hash(),
            ];

        $package = ExtensionPackage::create(array_merge($attributes, [
            'state' => $state,
            'state_reason' => $state === 'unsupported' ? 'Built for manifest version 2.' : null,
        ]));

        ExtensionConfig::create(['extension_id' => $id, 'enabled' => $enabled]);

        return $package;
    }

    public function testRuntimeGateSkipsQuarantinedPackageDespiteEnabledFlag(): void
    {
        $this->package('ext_supported', 'enabled', 3, true);
        $this->package('ext_unsupported', 'unsupported', 2, true);

        $loaded = ExtensionRuntimeGate::enabledExtensionIds();

        $this->assertContains('ext_supported', $loaded);
        $this->assertNotContains('ext_unsupported', $loaded);
        $this->assertFalse(ExtensionRuntimeGate::isEnabled('ext_unsupported'));
    }

    public function testRuntimeGateSkipsEveryNonExecutableState(): void
    {
        foreach (['failed', 'installing', 'uninstalling', 'staged'] as $state) {
            $this->package('ext_' . $state, $state, 3, true);
        }
        $this->package('ext_ok', 'enabled', 3, true);

        $this->assertSame(['ext_ok'], ExtensionRuntimeGate::enabledExtensionIds());
    }

    /**
     * Core extensions declared in config/modules/extensions.php have an
     * ExtensionConfig row but no installed package, so the state check must be
     * an exclusion list rather than a join — otherwise they would all vanish.
     */
    public function testConfigWithoutPackageRowStillLoads(): void
    {
        config()->set('modules.extensions.available.core_extension', [
            'name' => 'Core Extension',
        ]);
        ExtensionConfig::create(['extension_id' => 'core_extension', 'enabled' => true]);

        $this->assertContains('core_extension', ExtensionRuntimeGate::enabledExtensionIds());
    }

    public function testOrphanedConfigRowDoesNotBypassPackageTrustAsACoreExtension(): void
    {
        ExtensionConfig::create(['extension_id' => 'not_declared_by_core', 'enabled' => true]);

        $this->assertNotContains('not_declared_by_core', ExtensionRuntimeGate::enabledExtensionIds());
    }

    public function testInvalidPackageCannotFallBackToConfiguredCoreExtensionTrust(): void
    {
        config()->set('modules.extensions.available.ext_invalid_core_overlap', [
            'name' => 'Invalid Package Overlap',
        ]);
        $this->package('ext_invalid_core_overlap', 'unsupported', 2, true);

        $this->assertNotContains('ext_invalid_core_overlap', ExtensionRuntimeGate::enabledExtensionIds());
    }

    public function testDisabledSupportedPackageDoesNotLoad(): void
    {
        $this->package('ext_off', 'installed_disabled', 3, false);

        $this->assertNotContains('ext_off', ExtensionRuntimeGate::enabledExtensionIds());
    }

    public function testRuntimeStateChangesAreObservedWithoutProcessLocalInvalidation(): void
    {
        $package = $this->package('ext_live', 'enabled', 3, true);

        $this->assertTrue(ExtensionRuntimeGate::isEnabled('ext_live'));

        // Simulate another request/process disabling the extension. The
        // current process must not keep serving its previously-built plan.
        ExtensionConfig::query()
            ->where('extension_id', 'ext_live')
            ->update(['enabled' => false]);

        $this->assertFalse(ExtensionRuntimeGate::isEnabled('ext_live'));

        ExtensionConfig::query()
            ->where('extension_id', 'ext_live')
            ->update(['enabled' => true]);
        $this->assertTrue(ExtensionRuntimeGate::isEnabled('ext_live'));

        // Registry refreshes can revoke an installed package in a different
        // process too. Revocation must take effect on the next lookup.
        $package->update(['signature_state' => 'revoked']);

        $this->assertFalse(ExtensionRuntimeGate::isEnabled('ext_live'));
    }

    public function testChangingToAnotherValidRootMakesPreviouslyVerifiedPackagesInert(): void
    {
        $this->package('ext_rotated', 'enabled', 3, true);
        $this->assertTrue(ExtensionRuntimeGate::isEnabled('ext_rotated'));

        $newRoot = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        config()->set('extensions.signing.root_public_key', base64_encode($newRoot));
        config()->set('extensions.signing.root_fingerprint', hash('sha256', $newRoot));

        $this->assertFalse(ExtensionRuntimeGate::isEnabled('ext_rotated'));
    }

    /**
     * The capability projection is denormalized from the manifest so the
     * runtime plan can avoid reparsing package files. capability_hash is what
     * keeps that duplication honest: a projection edited in the database no
     * longer matches, and the package goes inert rather than running with
     * privileges nobody approved.
     */
    public function testATamperedCapabilityProjectionMakesThePackageInert(): void
    {
        $package = $this->package('ext_tampered', 'enabled', 3, true);

        $package->update([
            'capabilities' => (new ExtensionCapabilitySet(clientRoutes: true, adminRoutes: true))->jsonSerialize(),
        ]);
        ExtensionRuntimeGate::flush();

        $this->assertNotContains('ext_tampered', ExtensionRuntimeGate::enabledExtensionIds());
    }

    /** A v3 panel does not load a package built for an older manifest. */
    public function testAPackageBelowManifestV3DoesNotLoadEvenWhenMarkedEnabled(): void
    {
        $this->package('ext_old', 'enabled', 2, true);

        $this->assertNotContains('ext_old', ExtensionRuntimeGate::enabledExtensionIds());
    }
}
