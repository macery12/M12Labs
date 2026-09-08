<?php

namespace Everest\Tests\Integration\Api\Application\Extensions;

use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Everest\Services\Extensions\ExtensionRuntimeGate;
use Everest\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

/**
 * A quarantined package must not be re-enablable through the admin API.
 *
 * ExtensionRuntimeGate would decline to load it regardless, so allowing the
 * toggle would leave an administrator with an extension that reads as enabled
 * and silently does nothing.
 */
class ExtensionQuarantineApiTest extends ApplicationApiIntegrationTestCase
{
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

    private function package(string $id, string $state, int $manifestVersion): void
    {
        ExtensionPackage::create([
            'extension_id' => $id,
            'package_id' => $id,
            'name' => $id,
            'icon' => 'puzzle',
            'installed_version' => '1.0.0',
            'manifest' => ['manifestVersion' => $manifestVersion, 'extension' => ['id' => $id]],
            'manifest_version' => $manifestVersion,
            'state' => $state,
            'state_reason' => $state === 'unsupported'
                ? 'Built for manifest version 2. This panel requires manifest version 3.'
                : null,
        ]);

        ExtensionConfig::create(['extension_id' => $id, 'enabled' => false]);
    }

    public function testToggleRefusesToEnableQuarantinedPackage(): void
    {
        $this->package('ext_legacy', 'unsupported', 2);

        $response = $this->postJson('/api/application/extensions/ext_legacy/toggle');

        $response->assertStatus(422);
        $response->assertJsonPath('state', 'unsupported');
        $this->assertStringContainsString('manifest version 3', $response->json('error'));

        $this->assertFalse(ExtensionConfig::getByExtensionId('ext_legacy')->enabled);
    }

    public function testToggleEnablesSupportedPackageAndAdvancesState(): void
    {
        $this->package('ext_modern', 'installed_disabled', 3);

        $this->postJson('/api/application/extensions/ext_modern/toggle')->assertStatus(200);

        $this->assertTrue(ExtensionConfig::getByExtensionId('ext_modern')->enabled);
        $this->assertSame('enabled', ExtensionPackage::where('extension_id', 'ext_modern')->value('state'));
    }

    public function testUpdateRefusesToEnableQuarantinedPackage(): void
    {
        $this->package('ext_legacy_update', 'unsupported', 1);

        $this->putJson('/api/application/extensions/ext_legacy_update', ['enabled' => true])
            ->assertStatus(422);

        $this->assertFalse(ExtensionConfig::getByExtensionId('ext_legacy_update')->enabled);
    }
}
