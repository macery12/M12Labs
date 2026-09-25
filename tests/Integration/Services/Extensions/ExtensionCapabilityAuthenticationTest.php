<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Everest\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\ExtensionPackageUpdateService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\ExtensionPackageIntegrityService;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;
use Everest\Exceptions\Service\Extension\CapabilityApprovalRequiredException;

class ExtensionCapabilityAuthenticationTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('modules.extensions.enabled', true);
        config()->set('logging.default', 'null');
    }

    public function testExecutableV3PackageWithMissingCapabilityHashFailsClosed(): void
    {
        $package = $this->runtimePackage('capability_hash_missing');
        $package->update(['capability_hash' => null]);

        $this->assertArrayNotHasKey(
            'capability_hash_missing',
            app(ExtensionRuntimePlanService::class)->plan(),
        );
        $this->assertSame(
            'capability_hash',
            app(ExtensionRuntimePlanService::class)->exclusionReason($package->fresh()),
        );
    }

    public function testRecomputedAdjacentHashCannotAuthenticateATamperedProjection(): void
    {
        $package = $this->runtimePackage('capability_projection_tampered');
        $forged = new ExtensionCapabilitySet(clientRoutes: true, adminRoutes: true);
        $package->update([
            'capabilities' => $forged->jsonSerialize(),
            'capability_hash' => $forged->hash(),
        ]);

        $this->assertArrayNotHasKey(
            'capability_projection_tampered',
            app(ExtensionRuntimePlanService::class)->plan(),
        );
        $this->assertSame(
            'capability_hash',
            app(ExtensionRuntimePlanService::class)->exclusionReason($package->fresh()),
        );
    }

    public function testUpdateDiffIgnoresAPreseededPrivilegeUntilItIsExplicitlyApproved(): void
    {
        $installed = new ExtensionCapabilitySet(clientRoutes: true);
        $clientRoute = 'app/Extensions/Packages/capability_update/routes/client.php';
        $attributes = $this->signedRuntimePackageAttributes(
            'capability_update',
            $installed,
            [$clientRoute => "<?php\n"],
        );
        $package = ExtensionPackage::query()->create(array_merge($attributes, [
            'state' => 'installed_disabled',
        ]));
        $this->assertTrue(
            app(ExtensionPackageIntegrityService::class)->inspect($package)->manifestAuthentic,
        );

        $requested = new ExtensionCapabilitySet(clientRoutes: true, adminRoutes: true);
        $package->update([
            // An adjacent projection and unkeyed hash are both attacker
            // writable. Pre-seeding the new privilege must not make the later
            // signed update look like a non-escalation.
            'capabilities' => $requested->jsonSerialize(),
            'capability_hash' => $requested->hash(),
        ]);

        $newRawManifest = $attributes['manifest'];
        $newRawManifest['package']['version'] = '1.1.0';
        $newRawManifest['capabilities'] = $requested->jsonSerialize();
        $newRawManifest['files'][] = [
            'path' => 'app/Extensions/Packages/capability_update/routes/admin.php',
            'sha256' => hash('sha256', "<?php\n"),
        ];
        $newManifest = app(ExtensionManifestParser::class)->parse(
            $newRawManifest,
            'capability_update',
            '1.1.0',
        );
        $service = app(ExtensionPackageUpdateService::class);

        try {
            $service->assertCapabilitiesApproved($package->fresh(), $newManifest, null);
            $this->fail('The pre-seeded admin route should require explicit approval.');
        } catch (CapabilityApprovalRequiredException $exception) {
            $this->assertContains('routes.admin', $exception->diff->escalations);
            $approvalHash = $exception->diff->hash;
        }

        // Re-submitting the exact authenticated update diff is the explicit
        // approval path and must still work.
        $service->assertCapabilitiesApproved($package->fresh(), $newManifest, $approvalHash);
        $this->addToAssertionCount(1);
    }

    private function runtimePackage(string $id): ExtensionPackage
    {
        $package = ExtensionPackage::query()->create(array_merge(
            $this->signedRuntimePackageAttributes(
                $id,
                new ExtensionCapabilitySet(clientRoutes: true),
                [sprintf('app/Extensions/Packages/%s/routes/client.php', $id) => "<?php\n"],
            ),
            ['state' => 'enabled'],
        ));
        ExtensionConfig::query()->create(['extension_id' => $id, 'enabled' => true]);

        return $package;
    }
}
