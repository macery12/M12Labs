<?php

namespace Everest\Tests\Integration\Api\Application\Extensions;

use Everest\Models\ExtensionPackage;
use Everest\Services\Extensions\ExtensionRuntimeGate;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityDiff;
use Everest\Services\Extensions\Manifest\Definitions\HookDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PageDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PermissionDefinition;
use Everest\Services\Extensions\Manifest\Definitions\FrontendSlotDefinition;
use Everest\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * An update must never silently widen what a package can do.
 *
 * The diff is computed from the verified manifest, so these exercise the same
 * comparison the update path performs against the capabilities an
 * administrator previously approved.
 */
class ExtensionCapabilityApprovalTest extends ApplicationApiIntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        ExtensionRuntimeGate::flush();
    }

    public function tearDown(): void
    {
        ExtensionRuntimeGate::flush();

        parent::tearDown();
    }

    private function installed(ExtensionCapabilitySet $capabilities): ExtensionPackage
    {
        return ExtensionPackage::create([
            'extension_id' => 'demo',
            'package_id' => 'demo',
            'name' => 'Demo',
            'icon' => 'puzzle',
            'installed_version' => '1.0.0',
            'manifest' => ['manifestVersion' => 3],
            'manifest_version' => 3,
            // What ExtensionSignatureService::verify() records for a package
            // installed while no signing root was pinned. The column default is
            // 'unsigned', which no install path produces and which the runtime plan
            // refuses once a root exists — so a fixture that leaves it unset is not
            // a package this panel could actually have.
            'signature_state' => 'unsigned_acknowledged',
            'capabilities' => $capabilities->jsonSerialize(),
            'capability_hash' => $capabilities->hash(),
            'state' => 'installed_disabled',
        ]);
    }

    /**
     * The stored projection is the only record of what was approved, so the
     * update path must be able to rebuild it faithfully.
     */
    public function testTheStoredProjectionRoundTripsThroughHydration(): void
    {
        $capabilities = new ExtensionCapabilitySet(
            clientRoutes: true,
            adminPages: [new PageDefinition('main', 'ext.demo.nav', 'globe', 'modules', 10, null, 'read')],
            adminPermissions: [new PermissionDefinition('read', 'ext.demo.permission.read')],
            hooks: [new HookDefinition('server.pre_delete', 'Cleanup', 'synchronous_best_effort', 2500)],
            slots: [new FrontendSlotDefinition('server-layout.overlay', 'assistant-drawer', 20, 'control.console')],
        );

        $package = $this->installed($capabilities);

        $hydrated = app(ExtensionRuntimePlanService::class)->hydrateCapabilities($package->capabilities);

        $this->assertNotNull($hydrated);
        $this->assertSame($capabilities->hash(), $hydrated->hash());
    }

    /** A release that adds a destructive permission needs fresh consent. */
    public function testAddingAPermissionIsAnEscalationAgainstTheInstalledSet(): void
    {
        $installed = new ExtensionCapabilitySet(clientRoutes: true);
        $package = $this->installed($installed);

        $hydrated = app(ExtensionRuntimePlanService::class)->hydrateCapabilities($package->capabilities);

        $diff = ExtensionCapabilityDiff::between($hydrated, new ExtensionCapabilitySet(
            clientRoutes: true,
            adminPermissions: [new PermissionDefinition('delete', 'ext.demo.permission.delete', dangerous: true)],
        ));

        $this->assertTrue($diff->isEscalation());
        $this->assertContains('permission:delete (destructive)', $diff->escalations);
    }

    /** A release that only narrows proceeds without asking again. */
    public function testDroppingAPrivilegeNeedsNoApproval(): void
    {
        $installed = new ExtensionCapabilitySet(
            clientRoutes: true,
            adminPermissions: [new PermissionDefinition('delete', 'ext.demo.permission.delete')],
        );
        $package = $this->installed($installed);

        $hydrated = app(ExtensionRuntimePlanService::class)->hydrateCapabilities($package->capabilities);
        $diff = ExtensionCapabilityDiff::between($hydrated, new ExtensionCapabilitySet(clientRoutes: true));

        $this->assertFalse($diff->isEscalation());
    }

    /**
     * Consent is carried as a hash, so an approval for one capability set must
     * not authorise a different one.
     */
    public function testAnApprovalHashDoesNotTransferBetweenCapabilitySets(): void
    {
        $approved = ExtensionCapabilityDiff::between(null, new ExtensionCapabilitySet(clientRoutes: true));
        $requested = ExtensionCapabilityDiff::between(null, new ExtensionCapabilitySet(
            clientRoutes: true,
            secrets: [new \Everest\Services\Extensions\Manifest\Definitions\SecretDefinition('token', 'ext.demo.token')],
        ));

        $this->assertNotSame($approved->hash, $requested->hash);
        $this->assertFalse(hash_equals($requested->hash, $approved->hash));
    }

    /** Installing anything at all grants privileges, so it always asks. */
    public function testAFirstInstallAlwaysRequiresApproval(): void
    {
        $diff = ExtensionCapabilityDiff::between(null, new ExtensionCapabilitySet(clientRoutes: true));

        $this->assertTrue($diff->isEscalation());
    }
}
