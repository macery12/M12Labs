<?php

namespace Everest\Tests\Integration\Extensions\CustomDomains;

use Everest\Models\ExtensionConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Everest\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Extensions\Packages\custom_domains\Models\CustomDomain;
use Everest\Extensions\Packages\custom_domains\Services\PackageSettings;
use Everest\Extensions\Packages\custom_domains\Models\CustomDomainApiKey;
use Everest\Extensions\Packages\custom_domains\Models\ServerCustomDomain;
use Everest\Extensions\Packages\custom_domains\Services\CloudflareDnsService;
use Everest\Extensions\Packages\custom_domains\Services\CustomDomainProvisioningService;

/**
 * Provisioning against a fake Cloudflare.
 *
 * The package is installed into the panel tree at test time, so these exercise
 * the real classes rather than copies. Http::fake() stands in for the API: every
 * assertion here is about what the package does with Cloudflare's answers —
 * which records it creates, what it records on the mapping, what it withdraws,
 * and how it behaves when the API refuses.
 */
class CustomDomainProvisioningTest extends IntegrationTestCase
{
    // Aliased so the package's tables can be built BEFORE the transaction opens.
    // Creating them inside it commits it — sqlite and MySQL both do — which
    // silently drops isolation for this test and leaks its fixtures into every
    // test that runs after it in the same process.
    use DatabaseTransactions {
        beginDatabaseTransaction as private beginTransactionFromTrait;
    }

    private CustomDomainProvisioningService $service;

    public function beginDatabaseTransaction(): void
    {
        // Nothing to build when the package is absent; setUp() then skips.
        if (self::packageInstalled()) {
            $this->createPackageTables();
        }

        $this->beginTransactionFromTrait();
    }

    /**
     * These exercise the installed package's own classes, which live under
     * app/Extensions/Packages/ — a runtime artifact the installer writes, not
     * tracked source. With custom_domains uninstalled there is nothing to test,
     * and failing would report an absent optional package as a broken panel.
     *
     * The package is where these belong long-term; they live here because the
     * extensions repository has no PHP test harness.
     */
    private static function packageInstalled(): bool
    {
        return is_file(base_path('app/Extensions/Packages/custom_domains/Services/PackageSettings.php'));
    }

    public function setUp(): void
    {
        parent::setUp();

        if (!self::packageInstalled()) {
            $this->markTestSkipped('The custom_domains package is not installed on this panel.');
        }

        // The package reads its Cloudflare settings from extension_configs, so
        // point it at a base URL the fake owns and reset the request cache.
        ExtensionConfig::query()->updateOrCreate(
            ['extension_id' => PackageSettings::EXTENSION_ID],
            [
                'enabled' => true,
                'settings' => [
                    'cloudflare_base_url' => 'https://fake-cloudflare.test/client/v4',
                    'cloudflare_proxied' => false,
                    'cloudflare_retries' => 0,
                    'cloudflare_retry_sleep_ms' => 0,
                ],
            ],
        );
        PackageSettings::flush();

        $this->service = new CustomDomainProvisioningService(app(CloudflareDnsService::class));
    }

    protected function tearDown(): void
    {
        // tearDown() still runs after a skip, and the class is gone with the
        // package — so this must be guarded too.
        if (self::packageInstalled()) {
            PackageSettings::flush();
        }

        parent::tearDown();
    }

    public function testACnameMappingIsProvisionedAndRecorded(): void
    {
        $mapping = $this->mapping();

        Http::fake(fn ($request) => $request->method() === 'GET'
            // No record of this name exists yet, so the service creates one.
            ? Http::response(['success' => true, 'result' => []])
            : Http::response(['success' => true, 'result' => ['id' => 'rec-1', 'type' => 'CNAME']]));

        $this->service->provision($mapping);
        $mapping->refresh();

        $this->assertSame('active', $mapping->status);
        $this->assertNull($mapping->last_error);
        $this->assertNotNull($mapping->last_synced_at);
        $this->assertSame('rec-1', $mapping->dns_records[0]['id']);
        $this->assertSame('host', $mapping->dns_records[0]['kind']);

        $this->assertDatabaseHas('ext_custom_domains_dns_logs', [
            'server_custom_domain_id' => $mapping->id,
            'action' => 'sync',
            'status' => 'success',
        ]);
    }

    /**
     * Re-provisioning must converge rather than duplicate — it is what makes the
     * job safe to retry, and the queue retries it on any Cloudflare wobble.
     */
    public function testReProvisioningReplacesRecordsItNoLongerNeeds(): void
    {
        $mapping = $this->mapping();
        $mapping->forceFill(['dns_records' => [['kind' => 'host', 'id' => 'stale-1', 'type' => 'CNAME']]])->save();

        // A closure rather than URL patterns: Http::fake() resolves patterns by
        // reduce(), so the LAST matching pattern wins and a specific stub placed
        // before a wildcard is silently shadowed.
        Http::fake(function ($request) {
            if ($request->method() === 'DELETE') {
                return Http::response(['success' => true, 'result' => ['id' => 'stale-1']]);
            }
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'result' => []]);
            }

            return Http::response(['success' => true, 'result' => ['id' => 'rec-2', 'type' => 'CNAME']]);
        });

        $this->service->provision($mapping);
        $mapping->refresh();

        $this->assertSame('active', $mapping->status);
        $this->assertSame('rec-2', $mapping->dns_records[0]['id']);

        // The record it stopped needing was withdrawn, not orphaned at Cloudflare.
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/dns_records/stale-1'));
    }

    public function testCleanupWithdrawsEveryRecordItCreated(): void
    {
        $mapping = $this->mapping();
        $mapping->forceFill([
            'dns_records' => [
                ['kind' => 'host', 'id' => 'rec-a', 'type' => 'CNAME'],
                ['kind' => 'srv', 'id' => 'rec-b', 'type' => 'SRV', 'proto' => 'tcp'],
            ],
        ])->save();

        Http::fake(['*' => Http::response(['success' => true, 'result' => []])]);

        $this->service->cleanup($mapping);

        foreach (['rec-a', 'rec-b'] as $recordId) {
            Http::assertSent(fn ($request) => $request->method() === 'DELETE'
                && str_contains($request->url(), '/dns_records/' . $recordId));
        }
    }

    /**
     * A failure has to land on the mapping. An operator's only view of why a
     * subdomain is not working is status + last_error on this row.
     */
    public function testAFailedApiCallMarksTheMappingAndLogsIt(): void
    {
        $mapping = $this->mapping();

        Http::fake(['*' => Http::response(['success' => false, 'errors' => [['message' => 'Invalid zone']]], 403)]);

        $this->service->provision($mapping);
        $mapping->refresh();

        $this->assertSame('failed', $mapping->status);
        $this->assertNotNull($mapping->last_error);
        $this->assertDatabaseHas('ext_custom_domains_dns_logs', [
            'server_custom_domain_id' => $mapping->id,
            'action' => 'sync',
            'status' => 'failed',
        ]);
    }

    /**
     * Provisioning must not proceed with no credential: it would otherwise send
     * an unauthenticated request and report Cloudflare's 401 as the cause.
     */
    public function testAMappingWithNoCredentialFailsBeforeCallingCloudflare(): void
    {
        $mapping = $this->mapping();
        // A domain with no credential assigned. Blanking the token instead would
        // be rejected by the model's own validation.
        $mapping->customDomain->forceFill(['api_key_id' => null])->save();
        $mapping->unsetRelation('customDomain');

        Http::fake();

        $this->service->provision($mapping);
        $mapping->refresh();

        $this->assertSame('failed', $mapping->status);
        $this->assertStringContainsString('No API key', (string) $mapping->last_error);
        Http::assertNothingSent();
    }

    /**
     * The zone id is looked up once and cached on the domain row, so a bulk
     * re-provision does not spend a lookup per mapping.
     */
    public function testTheResolvedZoneIdIsCachedOnTheDomain(): void
    {
        $mapping = $this->mapping();
        $mapping->customDomain->forceFill(['cloudflare_zone_id' => null])->save();
        $mapping->unsetRelation('customDomain');

        Http::fake([
            '*/zones?*' => Http::response(['success' => true, 'result' => [['id' => 'zone-resolved']]]),
            '*/zones*' => Http::response(['success' => true, 'result' => [['id' => 'zone-resolved']]]),
        ]);

        $this->service->provision($mapping);

        $this->assertSame('zone-resolved', $mapping->customDomain->fresh()->cloudflare_zone_id);
    }

    private function mapping(): ServerCustomDomain
    {
        $server = $this->createServerModel();

        $key = CustomDomainApiKey::query()->create([
            'name' => 'primary',
            'token' => 'cf-token-value-long-enough',
            'enabled' => true,
        ]);

        $domain = CustomDomain::query()->create([
            'domain' => 'example.test',
            'cloudflare_zone_id' => 'zone-1',
            'api_key_id' => $key->id,
            'enabled' => true,
            'wildcard_enabled' => false,
        ]);

        return ServerCustomDomain::query()->create([
            'server_id' => $server->id,
            'allocation_id' => $server->allocation_id,
            'custom_domain_id' => $domain->id,
            'subdomain' => 'play',
            'full_domain' => 'play.example.test',
            'port' => 25565,
            'protocol' => 'both',
            'record_type' => 'cname',
            'status' => 'pending',
        ]);
    }

    /**
     * The package's own migration is not part of the panel's chain, so the test
     * database does not have these tables. Build the shape the models expect.
     */
    private function createPackageTables(): void
    {
        if (Schema::hasTable('ext_custom_domains_domains')) {
            return;
        }

        Schema::create('ext_custom_domains_api_keys', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique();
            $table->text('token');
            $table->boolean('enabled')->default(1);
            $table->timestamps();
        });

        Schema::create('ext_custom_domains_domains', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('domain')->unique();
            $table->string('cloudflare_zone_id')->nullable();
            $table->unsignedBigInteger('api_key_id')->nullable();
            $table->json('allowed_nest_ids')->nullable();
            $table->json('allowed_egg_ids')->nullable();
            $table->string('service_tag')->nullable();
            $table->json('egg_service_tags')->nullable();
            $table->boolean('wildcard_enabled')->default(0);
            $table->boolean('enabled')->default(1);
            $table->timestamps();
        });

        Schema::create('ext_custom_domains_server_domains', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('allocation_id')->nullable();
            $table->unsignedBigInteger('custom_domain_id');
            $table->string('subdomain');
            $table->string('full_domain');
            $table->unsignedInteger('port');
            $table->string('protocol')->default('both');
            $table->string('record_type')->nullable();
            $table->string('service_tag')->nullable();
            $table->string('status')->default('pending');
            $table->json('dns_records')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ext_custom_domains_dns_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('server_id')->nullable();
            $table->unsignedBigInteger('server_custom_domain_id')->nullable();
            $table->string('action');
            $table->string('status');
            $table->json('payload')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }
}
