<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionSecret;
use Everest\Models\ExtensionPackage;
use Everest\Exceptions\DisplayException;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Extensions\ExtensionSecretStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\Definitions\SecretDefinition;

/**
 * The encrypted store an extension's credentials live in.
 *
 * The reason it is not extension_configs.settings is the first test here: that
 * column is returned by the catalog API, so a token in it is readable by
 * anybody who can list extensions.
 */
class ExtensionSecretStoreTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function setUp(): void
    {
        parent::setUp();

        ExtensionRuntimePlanService::flush();
        config()->set('modules.extensions.enabled', true);

        $capabilities = new ExtensionCapabilitySet(
            secrets: [new SecretDefinition(key: 'api_token', labelKey: 'ext.demo.secret.token')],
        );

        ExtensionPackage::create(array_merge($this->signedRuntimePackageAttributes('demo', $capabilities), [
            'name' => 'Demo',
            'state' => 'enabled',
        ]));
        ExtensionConfig::create(['extension_id' => 'demo', 'enabled' => true]);
        ExtensionRuntimePlanService::flush();
    }

    public function tearDown(): void
    {
        ExtensionRuntimePlanService::flush();

        parent::tearDown();
    }

    private function store(): ExtensionSecretStore
    {
        return app(ExtensionSecretStore::class);
    }

    public function testAStoredSecretRoundTripsAndIsNeverAtRestInPlaintext(): void
    {
        $this->store()->put('demo', 'api_token', 'cf-live-abcdef');

        $this->assertSame('cf-live-abcdef', $this->store()->get('demo', 'api_token'));

        $raw = ExtensionSecret::query()->where('extension_id', 'demo')->value('value');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('cf-live-abcdef', $raw);
    }

    /**
     * Only what the manifest declares. An update that drops a secret makes the
     * old value unreachable rather than quietly still readable.
     */
    public function testAnUndeclaredKeyIsRefusedForBothReadAndWrite(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not declare a secret named [other]');

        $this->store()->put('demo', 'other', 'value');
    }

    public function testReadingAnUndeclaredKeyIsAlsoRefused(): void
    {
        $this->expectException(DisplayException::class);

        $this->store()->get('demo', 'other');
    }

    /**
     * The context binding. A ciphertext row moved to another extension still
     * decrypts to the same bytes, so identity has to be checked separately —
     * otherwise an operator with database access could hand one extension
     * another's credential by moving a row.
     */
    public function testACiphertextMovedBetweenExtensionsIsInert(): void
    {
        $this->store()->put('demo', 'api_token', 'cf-live-abcdef');

        // Same value, re-pointed at a different extension id.
        ExtensionSecret::query()->where('extension_id', 'demo')->update(['extension_id' => 'other']);

        $capabilities = new ExtensionCapabilitySet(
            secrets: [new SecretDefinition(key: 'api_token', labelKey: 'ext.other.secret.token')],
        );
        ExtensionPackage::create(array_merge($this->signedRuntimePackageAttributes('other', $capabilities), [
            'name' => 'Other',
            'state' => 'enabled',
        ]));
        ExtensionConfig::create(['extension_id' => 'other', 'enabled' => true]);
        ExtensionRuntimePlanService::flush();

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not belong to it');

        $this->store()->get('other', 'api_token');
    }

    /**
     * The admin form writes blind, so a blank field means "unchanged". Treating
     * it as "clear" would destroy a working credential on any unrelated save.
     */
    public function testAnEmptyWriteLeavesTheStoredValueAlone(): void
    {
        $this->store()->put('demo', 'api_token', 'cf-live-abcdef');
        $this->store()->put('demo', 'api_token', '   ');

        $this->assertSame('cf-live-abcdef', $this->store()->get('demo', 'api_token'));
    }

    /** Replacing bumps the version, which is part of the context binding. */
    public function testReplacingRotatesTheVersion(): void
    {
        $this->store()->put('demo', 'api_token', 'first');
        $this->store()->put('demo', 'api_token', 'second');

        $row = ExtensionSecret::query()->where('extension_id', 'demo')->firstOrFail();

        $this->assertSame(2, $row->key_version);
        $this->assertNotNull($row->rotated_at);
        $this->assertSame('second', $this->store()->get('demo', 'api_token'));
    }

    /** describe() is what the API returns, and it must carry no value. */
    public function testDescribeExposesMetadataOnly(): void
    {
        $this->store()->put('demo', 'api_token', 'cf-live-abcdef');

        $described = $this->store()->describe('demo');

        $this->assertCount(1, $described);
        $this->assertTrue($described[0]['configured']);
        $this->assertSame('api_token', $described[0]['key']);
        $this->assertStringNotContainsString('cf-live-abcdef', json_encode($described));
    }

    public function testPurgeDestroysEveryCredential(): void
    {
        $this->store()->put('demo', 'api_token', 'cf-live-abcdef');

        $this->assertSame(1, $this->store()->purge('demo'));
        $this->assertFalse($this->store()->configured('demo', 'api_token'));
    }
}
