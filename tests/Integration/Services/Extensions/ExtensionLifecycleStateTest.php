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

        $package = ExtensionPackage::create([
            'extension_id' => $id,
            'package_id' => $id,
            'name' => $id,
            'icon' => 'puzzle',
            'installed_version' => '1.0.0',
            'manifest' => ['manifestVersion' => $manifestVersion, 'extension' => ['id' => $id]],
            'manifest_version' => $manifestVersion,
            // What ExtensionSignatureService::verify() records for a package
            // installed while no signing root was pinned. The column default is
            // 'unsigned', which no install path produces and which the runtime
            // plan refuses once a root exists — so a fixture leaving it unset is
            // not a package this panel could actually have.
            'signature_state' => 'unsigned_acknowledged',
            'capabilities' => $capabilities->jsonSerialize(),
            'capability_hash' => $capabilities->hash(),
            'state' => $state,
            'state_reason' => $state === 'unsupported' ? 'Built for manifest version 2.' : null,
        ]);

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
        ExtensionConfig::create(['extension_id' => 'core_extension', 'enabled' => true]);

        $this->assertContains('core_extension', ExtensionRuntimeGate::enabledExtensionIds());
    }

    public function testDisabledSupportedPackageDoesNotLoad(): void
    {
        $this->package('ext_off', 'installed_disabled', 3, false);

        $this->assertNotContains('ext_off', ExtensionRuntimeGate::enabledExtensionIds());
    }

    /**
     * The capability projection is denormalized from the manifest so the
     * runtime plan stays a single cheap query. capability_hash is what keeps
     * that duplication honest: a projection edited in the database no longer
     * matches, and the package goes inert rather than running with privileges
     * nobody approved.
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
