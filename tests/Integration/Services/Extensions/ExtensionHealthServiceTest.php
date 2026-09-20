<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionSecret;
use Everest\Models\ExtensionPackage;
use Illuminate\Support\Facades\File;
use Everest\Models\ExtensionQueueJob;
use Everest\Models\ExtensionPermission;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Extensions\ExtensionHealthService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\Definitions\QueueDefinition;

/**
 * The computed health record.
 *
 * Nothing here is stored: every field is derived on read from whatever is
 * already authoritative. These tests pin the derivations that an operator
 * actually acts on — above all the difference between "enabled" and "actually
 * loads", which is what the admin page previously could not tell them.
 */
class ExtensionHealthServiceTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function setUp(): void
    {
        parent::setUp();

        ExtensionRuntimePlanService::flush();
        config()->set('modules.extensions.enabled', true);
        app(ExtensionHealthService::class)->flush('healthdemo');
    }

    public function tearDown(): void
    {
        app(ExtensionHealthService::class)->flush('healthdemo');
        ExtensionRuntimePlanService::flush();

        parent::tearDown();
    }

    private function install(string $state = 'enabled', bool $enabled = true): ExtensionPackage
    {
        $capabilities = new ExtensionCapabilitySet(
            clientRoutes: true,
            queues: [new QueueDefinition(name: 'sync')],
        );

        $package = ExtensionPackage::create(array_merge($this->signedRuntimePackageAttributes(
            'healthdemo',
            $capabilities,
            [
                'app/Extensions/Packages/healthdemo/routes/client.php' => "<?php\n",
                'app/Extensions/Packages/healthdemo/Jobs/HealthJob.php' => "<?php\n",
            ],
        ), [
            'name' => 'Health Demo',
            'state' => $state,
        ]));

        ExtensionConfig::create(['extension_id' => 'healthdemo', 'enabled' => $enabled]);
        ExtensionRuntimePlanService::flush();

        return $package;
    }

    private function health(): array
    {
        app(ExtensionHealthService::class)->flush('healthdemo');

        return app(ExtensionHealthService::class)->forExtension('healthdemo');
    }

    public function testAnUninstalledExtensionReportsAsSuch(): void
    {
        $health = $this->health();

        $this->assertFalse($health['installed']);
    }

    public function testAHealthyExtensionReportsLoadable(): void
    {
        $this->install();

        $health = $this->health();

        $this->assertTrue($health['installed']);
        $this->assertTrue($health['loadable']);
        $this->assertSame('enabled', $health['state']);
        $this->assertTrue($health['integrity']['capabilityProjectionMatches']);
    }

    /**
     * The distinction the admin page could not previously make: enabled in the
     * config, quarantined in its lifecycle state, therefore inert.
     */
    public function testAQuarantinedExtensionIsEnabledButNotLoadable(): void
    {
        $this->install(state: 'unsupported', enabled: true);

        $health = $this->health();

        $this->assertFalse($health['loadable']);
        $this->assertSame('unsupported', $health['state']);
    }

    /**
     * A projection edited in the database no longer matches its hash, which is
     * exactly why the package goes inert. Health has to say so, or the state
     * looks like an unexplained failure.
     */
    public function testATamperedProjectionIsReported(): void
    {
        $package = $this->install();

        $package->update([
            'capabilities' => (new ExtensionCapabilitySet(clientRoutes: true, adminRoutes: true))->jsonSerialize(),
        ]);
        ExtensionRuntimePlanService::flush();

        $health = $this->health();

        $this->assertFalse($health['integrity']['capabilityProjectionMatches']);
        $this->assertFalse($health['loadable']);
    }

    public function testMissingAndModifiedFilesAreReported(): void
    {
        $package = $this->install();

        File::delete(base_path('app/Extensions/Packages/healthdemo/routes/client.php'));

        $health = $this->health();

        $this->assertSame(2, $health['integrity']['trackedFiles']);
        $this->assertSame(
            ['app/Extensions/Packages/healthdemo/routes/client.php'],
            $health['integrity']['missingFiles']
        );
    }

    public function testQueueFailuresSurfaceWithoutThePayload(): void
    {
        $this->install();

        ExtensionQueueJob::create([
            'job_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'extension_id' => 'healthdemo',
            'queue_name' => 'sync',
            'job_class' => 'Everest\\Extensions\\Packages\\healthdemo\\Jobs\\SyncJob',
            'status' => ExtensionQueueJob::STATUS_FAILED,
            'finished_at' => now(),
            'last_error' => 'RuntimeException: upstream refused the request',
        ]);

        $health = $this->health();

        $this->assertSame(1, $health['queues']['byStatus']['failed']);
        $this->assertStringContainsString('upstream refused', $health['queues']['lastFailure']['error']);
    }

    public function testPendingPermissionApprovalsAreVisible(): void
    {
        $this->install();

        ExtensionPermission::create([
            'extension_id' => 'healthdemo',
            'action' => 'read',
            'identifier' => 'ext.healthdemo.admin.read',
            'label_key' => 'ext.healthdemo.permission.read',
        ]);

        $health = $this->health();

        $this->assertSame(1, $health['permissions']['declared']);
        $this->assertSame(1, $health['permissions']['pendingApproval']);
    }

    /**
     * The export goes into a support thread, so it must carry key names and
     * nothing derived from a value.
     */
    public function testTheExportNeverCarriesACredentialValue(): void
    {
        $this->install();

        ExtensionSecret::create([
            'extension_id' => 'healthdemo',
            'key' => 'api-token',
            'value' => 'ciphertext-that-should-never-appear',
            'key_version' => 1,
            'context_hash' => str_repeat('b', 64),
        ]);

        $export = app(ExtensionHealthService::class)->export('healthdemo');
        $encoded = json_encode($export);

        $this->assertStringContainsString('api-token', $encoded);
        $this->assertStringNotContainsString('ciphertext-that-should-never-appear', $encoded);
        $this->assertTrue($export['extension']['secrets'][0]['configured']);
    }
}
