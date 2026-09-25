<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Illuminate\Support\Facades\File;
use Everest\Models\ExtensionPackageFile;
use Everest\Models\ExtensionSignatureAudit;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Extensions\ExtensionHealthService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;

class ExtensionRuntimeIntegrityTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    private const ROUTE_PATH = 'app/Extensions/Packages/integrity_fixture/routes/client.php';

    private const ORIGINAL_ROUTE = "<?php\n\$GLOBALS['integrity_fixture_route'] = 'authentic';\n";

    private const TAMPERED_ROUTE = "<?php\n\$GLOBALS['integrity_fixture_route'] = 'tampered';\n";

    public function setUp(): void
    {
        parent::setUp();

        config()->set('modules.extensions.enabled', true);
        config()->set('logging.default', 'null');
        unset($GLOBALS['integrity_fixture_route']);
    }

    public function tearDown(): void
    {
        unset($GLOBALS['integrity_fixture_route']);

        parent::tearDown();
    }

    public function testSignedRouteTamperingIsQuarantinedAndRestoringAuthenticBytesMakesItLoadable(): void
    {
        $capabilities = new ExtensionCapabilitySet(clientRoutes: true);
        $package = ExtensionPackage::query()->create(array_merge(
            $this->signedRuntimePackageAttributes(
                'integrity_fixture',
                $capabilities,
                [self::ROUTE_PATH => self::ORIGINAL_ROUTE],
            ),
            ['state' => 'enabled'],
        ));
        ExtensionConfig::query()->create(['extension_id' => 'integrity_fixture', 'enabled' => true]);
        ExtensionPackageFile::query()->create([
            'extension_package_id' => $package->id,
            'path' => self::ROUTE_PATH,
            'operation' => 'created',
            'installed_checksum' => hash('sha256', self::ORIGINAL_ROUTE),
        ]);

        $this->assertArrayHasKey(
            'integrity_fixture',
            app(ExtensionRuntimePlanService::class)->withCapability('routes.client'),
        );
        $this->loadClientRouteFixtures();
        $this->assertSame('authentic', $GLOBALS['integrity_fixture_route'] ?? null);

        File::put(base_path(self::ROUTE_PATH), self::TAMPERED_ROUTE);
        // The rollback tracking row is adjacent mutable metadata, not the
        // integrity authority. Even making it agree with the hostile bytes
        // cannot bypass the signed manifest's expected checksum.
        $package->files()->update([
            'installed_checksum' => hash('sha256', self::TAMPERED_ROUTE),
        ]);
        unset($GLOBALS['integrity_fixture_route']);

        $this->assertArrayNotHasKey(
            'integrity_fixture',
            app(ExtensionRuntimePlanService::class)->withCapability('routes.client'),
        );
        $this->loadClientRouteFixtures();

        $this->assertArrayNotHasKey('integrity_fixture_route', $GLOBALS);
        $this->assertSame('failed', $package->fresh()->state);
        $this->assertStringContainsString('runtime-integrity', (string) $package->fresh()->state_reason);
        $this->assertSame(
            ExtensionSignatureAudit::VERDICT_INTEGRITY_FAILED,
            ExtensionSignatureAudit::query()
                ->where('extension_id', 'integrity_fixture')
                ->latest('id')
                ->value('verdict'),
        );
        $this->assertSame(1, ExtensionSignatureAudit::query()
            ->where('extension_id', 'integrity_fixture')
            ->where('verdict', ExtensionSignatureAudit::VERDICT_INTEGRITY_FAILED)
            ->count());

        $healthService = app(ExtensionHealthService::class);
        $healthService->flush('integrity_fixture');
        $health = $healthService->forExtension('integrity_fixture');
        $this->assertFalse($health['loadable']);
        $this->assertTrue($health['integrity']['manifestAuthentic']);
        $this->assertFalse($health['integrity']['runtimeVerified']);
        $this->assertSame([self::ROUTE_PATH], $health['integrity']['modifiedFiles']);

        File::put(base_path(self::ROUTE_PATH), self::ORIGINAL_ROUTE);
        $this->loadClientRouteFixtures();

        $this->assertSame('authentic', $GLOBALS['integrity_fixture_route'] ?? null);
        $this->assertSame('enabled', $package->fresh()->state);
        $this->assertNull($package->fresh()->state_reason);
        $this->assertSame(
            ExtensionSignatureAudit::VERDICT_INTEGRITY_RESTORED,
            ExtensionSignatureAudit::query()
                ->where('extension_id', 'integrity_fixture')
                ->latest('id')
                ->value('verdict'),
        );
    }

    public function testChangingBothTheRetainedFileHashAndInstalledBytesCannotBypassManifestReverification(): void
    {
        $capabilities = new ExtensionCapabilitySet(clientRoutes: true);
        $attributes = $this->signedRuntimePackageAttributes(
            'integrity_fixture',
            $capabilities,
            [self::ROUTE_PATH => self::ORIGINAL_ROUTE],
        );
        $package = ExtensionPackage::query()->create(array_merge($attributes, ['state' => 'enabled']));
        ExtensionConfig::query()->create(['extension_id' => 'integrity_fixture', 'enabled' => true]);

        File::put(base_path(self::ROUTE_PATH), self::TAMPERED_ROUTE);
        $forgedManifest = $attributes['manifest'];
        $forgedManifest['files'][0]['sha256'] = hash('sha256', self::TAMPERED_ROUTE);
        $package->update([
            'manifest' => $forgedManifest,
            'signed_manifest' => json_encode(
                $forgedManifest,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            'manifest_hash' => hash('sha256', 'attacker-controlled-adjacent-digest'),
        ]);

        $this->loadClientRouteFixtures();

        $this->assertArrayNotHasKey('integrity_fixture_route', $GLOBALS);
        $this->assertSame('failed', $package->fresh()->state);
        $health = app(ExtensionHealthService::class)->forExtension('integrity_fixture');
        $this->assertFalse($health['integrity']['manifestAuthentic']);
        $this->assertStringContainsString('signature', $health['integrity']['failureReason']);
    }

    /** Execute the same plan-driven decision made by the client route loader. */
    private function loadClientRouteFixtures(): void
    {
        foreach (app(ExtensionRuntimePlanService::class)->withCapability('routes.client') as $id => $entry) {
            $route = app_path(sprintf('Extensions/Packages/%s/routes/client.php', $id));
            if (is_file($route)) {
                require $route;
            }
        }
    }
}
