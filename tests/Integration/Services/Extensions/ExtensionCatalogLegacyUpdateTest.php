<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Everest\Models\ExtensionRepository;
use Everest\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\ExtensionCatalogService;
use Everest\Services\Extensions\ExtensionRemoteUrlGuard;
use Everest\Services\Extensions\ExtensionMigrationService;
use Everest\Services\Extensions\ExtensionSignatureService;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\ExtensionRemoteResourceService;
use Everest\Services\Extensions\ExtensionPackageArtifactService;
use Everest\Services\Extensions\ExtensionRepositoryBootstrapService;

class ExtensionCatalogLegacyUpdateTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function testManualV1PackageCanUpgradeDirectlyFromMatchingRepository(): void
    {
        config()->set('modules.extensions.available', []);

        $repository = ExtensionRepository::query()->create([
            'slug' => 'official-test',
            'name' => 'Official Test Repository',
            'manifest_url' => 'https://extensions.example/registry.json',
            'homepage_url' => 'https://extensions.example',
            'enabled' => true,
            'is_official' => true,
        ]);

        ExtensionPackage::query()->create([
            'extension_id' => 'minecraft_icon_builder',
            'package_id' => 'minecraft_icon_builder',
            'name' => 'Minecraft Icon Builder',
            'icon' => 'image',
            'installed_version' => '2.0.0',
            // The v1 format omitted manifestVersion entirely.
            'manifest' => [
                'package' => ['id' => 'minecraft_icon_builder', 'version' => '2.0.0'],
                'extension' => ['id' => 'minecraft_icon_builder', 'name' => 'Minecraft Icon Builder'],
            ],
            'manifest_version' => 1,
            'signature_state' => 'unsigned_acknowledged',
            'state' => 'unsupported',
            'state_reason' => 'Built for manifest version 1. This panel requires manifest version 3.',
            'source_repository_id' => null,
            'source_repository_name' => 'Manual package file',
        ]);
        ExtensionConfig::query()->create([
            'extension_id' => 'minecraft_icon_builder',
            'enabled' => false,
        ]);

        $catalog = $this->catalogService($this->registry());
        $extension = collect($catalog->getCatalog()['extensions'])
            ->firstWhere('id', 'minecraft_icon_builder');

        $this->assertIsArray($extension);
        $this->assertSame('unsupported', $extension['status']);
        $this->assertSame(1, $extension['manifestVersion']);
        $this->assertSame('3.0.0', $extension['latestVersion']);
        $this->assertTrue($extension['updateAvailable']);
        $this->assertSame($repository->id, $extension['source']['repositoryId']);
        $this->assertSame('Official Test Repository', $extension['source']['label']);
    }

    /**
     * @param array<string, mixed> $registry
     */
    private function catalogService(array $registry): ExtensionCatalogService
    {
        $bootstrap = \Mockery::mock(ExtensionRepositoryBootstrapService::class);
        $bootstrap->expects('ensureOfficialRepository')->once();

        $migration = \Mockery::mock(ExtensionMigrationService::class);
        $migration->allows('hasMigrations')->andReturnFalse();

        $artifact = \Mockery::mock(ExtensionPackageArtifactService::class);
        $artifact->allows('isCompatiblePanelVersions')->andReturnTrue();

        $signature = \Mockery::mock(ExtensionSignatureService::class);
        $signature->allows('isReleaseKeyUsable')->andReturnTrue();
        $signature->allows('isRollback')->andReturnFalse();

        $urlGuard = \Mockery::mock(ExtensionRemoteUrlGuard::class);
        $urlGuard->allows('assertSafeHttpsUrl')->andReturn([
            'host' => 'extensions.example',
            'port' => 443,
            'addresses' => ['203.0.113.10'],
            'literalIp' => false,
        ]);

        $remote = \Mockery::mock(ExtensionRemoteResourceService::class);
        $remote->expects('getContents')->once()->andReturn(json_encode($registry, JSON_THROW_ON_ERROR));

        return new ExtensionCatalogService(
            $bootstrap,
            $migration,
            $artifact,
            \Mockery::mock(ExtensionRuntimePlanService::class),
            $signature,
            $urlGuard,
            $remote,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function registry(): array
    {
        return [
            'schemaVersion' => 2,
            'packages' => [[
                'id' => 'minecraft_icon_builder',
                'name' => 'Minecraft Icon Builder',
                'description' => 'Build a Minecraft server icon.',
                'author' => 'M12Labs',
                'icon' => 'palette',
                'versions' => [[
                    'version' => '3.0.0',
                    'archive' => 'packages/minecraft_icon_builder/3.0.0/minecraft_icon_builder.M12LabsExtension',
                    'sha256' => str_repeat('a', 64),
                    'manifestVersion' => 3,
                    'compatiblePanelVersions' => ['>=Alpha 4.0 <Alpha 5.0'],
                    'signature' => [
                        'keyId' => 'test-release-key',
                        'value' => base64_encode(str_repeat('s', 64)),
                    ],
                ]],
                'capabilitySummary' => [
                    'serverPages' => 1,
                    'clientRoutes' => true,
                ],
            ]],
        ];
    }
}
