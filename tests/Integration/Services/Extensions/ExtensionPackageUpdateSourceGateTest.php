<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionPackage;
use Illuminate\Support\Facades\File;
use Everest\Models\ExtensionRepository;
use Everest\Exceptions\DisplayException;
use Everest\Models\ExtensionPackageFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\ExtensionCatalogService;
use Everest\Services\Extensions\ExtensionJobDrainService;
use Everest\Services\Extensions\ExtensionMigrationService;
use Everest\Services\Extensions\ExtensionPhpSourceScanner;
use Everest\Services\Extensions\ExtensionSignatureService;
use Everest\Services\Extensions\ExtensionPackageFileService;
use Everest\Services\Extensions\ExtensionPermissionRegistry;
use Everest\Services\Extensions\ExtensionRequirementService;
use Everest\Services\Extensions\ExtensionPageManifestService;
use Everest\Services\Extensions\ExtensionPanelRebuildService;
use Everest\Services\Extensions\ExtensionOperationLockService;
use Everest\Services\Extensions\ExtensionPackageSourceScanner;
use Everest\Services\Extensions\ExtensionPackageUpdateService;
use Everest\Services\Extensions\ExtensionFrontendImportScanner;
use Everest\Services\Extensions\ExtensionRemoteResourceService;
use Everest\Services\Extensions\ExtensionInstallProgressService;
use Everest\Services\Extensions\ExtensionPackageArtifactService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\ExtensionPackageIntegrityService;
use Everest\Services\Extensions\ExtensionFilesystemOwnershipService;

class ExtensionPackageUpdateSourceGateTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    private string $workspace;

    private string $targetPath;

    private const SAFE_SOURCE = "<?php\n\nnamespace Everest\\Extensions\\Packages\\demo;\n";

    private const BLOCKED_SOURCE = "<?php\n\nnamespace Everest\\Extensions\\Packages\\demo;\n\nexec('id');\n";

    public function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir() . '/extension-update-source-gate-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->workspace);

        $path = 'app/Extensions/Packages/demo/Support/SourceGateFixture.php';
        $attributes = $this->signedRuntimePackageAttributes(
            'demo',
            new ExtensionCapabilitySet(),
            [$path => self::SAFE_SOURCE],
        );
        $package = ExtensionPackage::query()->create(array_merge($attributes, [
            'state' => 'installed_disabled',
        ]));
        ExtensionPackageFile::query()->create([
            'extension_package_id' => $package->id,
            'path' => $path,
            'operation' => 'created',
            'installed_checksum' => hash('sha256', self::SAFE_SOURCE),
        ]);

        $this->targetPath = base_path($path);

        // These fixtures exercise source policy rather than signing policy.
        // Development mode without a trust root proceeds to the source gate.
        config()->set('extensions.signing.require_signature', false);
        config()->set('extensions.signing.root_public_key');
        config()->set('extensions.signing.root_fingerprint');
        config()->set('logging.default', 'null');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    #[DataProvider('updateSources')]
    public function testBlockedPhpIsRejectedBeforeMutationForEveryUpdateSource(string $source): void
    {
        $archive = $this->archive();
        $service = $this->service($archive, $source);

        try {
            if ($source === 'repository') {
                $service->update('demo', 123, '1.1.0');
            } else {
                $service->updateFromArchive($archive, 'Local fixture');
            }

            $this->fail(sprintf('The %s update should have rejected blocked PHP.', $source));
        } catch (DisplayException $exception) {
            $this->assertStringContainsString("package's PHP is not installable", $exception->getMessage());
            $this->assertStringContainsString('exec()', $exception->getMessage());
        }

        $this->assertSame(self::SAFE_SOURCE, File::get($this->targetPath));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function updateSources(): array
    {
        return [
            'repository release' => ['repository'],
            'local archive' => ['local'],
        ];
    }

    private function service(string $archive, string $source): ExtensionPackageUpdateService
    {
        $repository = (new ExtensionRepository([
            'slug' => 'fixture',
            'name' => 'Fixture repository',
            'manifest_url' => 'https://extensions.example.test/registry.json',
            'enabled' => true,
        ]))->forceFill(['id' => 123]);

        $catalog = \Mockery::mock(ExtensionCatalogService::class);
        if ($source === 'repository') {
            $catalog->expects('findRepositoryPackage')->once()->with('demo', 123, '1.1.0')->andReturn([
                'latestRelease' => [
                    'archiveUrl' => 'https://extensions.example.test/demo-1.1.0.M12LabsExtension',
                    'archiveChecksum' => hash_file('sha256', $archive),
                    'version' => '1.1.0',
                    'compatiblePanelVersions' => [],
                ],
                'repository' => $repository,
            ]);
            $catalog->expects('assertRepositoryEnabled')->once()->with(123);
        }

        $remote = \Mockery::mock(ExtensionRemoteResourceService::class);
        if ($source === 'repository') {
            $remote->expects('download')->once()->andReturnUsing(
                static function (string $location, string $destination) use ($archive): void {
                    File::copy($archive, $destination);
                }
            );
        }
        $this->app->instance(ExtensionRemoteResourceService::class, $remote);
        $artifact = $this->app->make(ExtensionPackageArtifactService::class);

        $lock = \Mockery::mock(ExtensionOperationLockService::class);
        $lock->expects('withinLock')->once()->andReturnUsing(
            static fn (string $action, string $subject, callable $callback) => $callback()
        );

        $progress = \Mockery::mock(ExtensionInstallProgressService::class);
        $progress->allows('report');
        $progress->expects('clear')->once();

        $ownership = \Mockery::mock(ExtensionFilesystemOwnershipService::class);
        // Rejection cleanup repairs permissions, but must not replace package
        // bytes. All earlier ownership work belongs after the source gate.
        $ownership->expects('repairStandardPaths')->once()->with('demo');

        $fileService = \Mockery::mock(ExtensionPackageFileService::class);
        $fileService->shouldNotReceive('assertFilesUnmodified');
        $fileService->shouldNotReceive('createRollbackSnapshot');
        $fileService->shouldNotReceive('restoreRollbackSnapshot');

        $drain = \Mockery::mock(ExtensionJobDrainService::class);
        $drain->shouldNotReceive('beginDrain');
        $drain->shouldNotReceive('waitForDrain');
        $drain->shouldNotReceive('assertSafeToRemove');
        $drain->expects('endDrain')->once()->with('demo');

        $rebuild = \Mockery::mock(ExtensionPanelRebuildService::class);
        $rebuild->shouldNotReceive('rebuild');

        $requirements = \Mockery::mock(ExtensionRequirementService::class);
        $requirements->expects('assertSatisfied')->once();
        $requirements->allows('providedNpmPackages')->andReturn([]);
        $sourceScanner = new ExtensionPackageSourceScanner(
            new ExtensionFrontendImportScanner($requirements),
            $this->app->make(ExtensionPhpSourceScanner::class),
        );

        return new ExtensionPackageUpdateService(
            $catalog,
            $rebuild,
            $lock,
            $ownership,
            $progress,
            $artifact,
            $fileService,
            \Mockery::mock(ExtensionMigrationService::class),
            $this->app->make(ExtensionPackageIntegrityService::class),
            \Mockery::mock(ExtensionPermissionRegistry::class),
            $drain,
            \Mockery::mock(ExtensionPageManifestService::class),
            $this->app->make(ExtensionSignatureService::class),
            $sourceScanner,
            $requirements,
        );
    }

    private function archive(): string
    {
        $path = 'app/Extensions/Packages/demo/Support/SourceGateFixture.php';
        $manifest = [
            'manifestVersion' => 3,
            'package' => ['id' => 'demo', 'version' => '1.1.0', 'publisher' => 'integration-tests'],
            'extension' => [
                'id' => 'demo',
                'name' => 'Demo',
                'description' => 'Source-gate update fixture.',
                'icon' => 'puzzle',
                'defaults' => ['enabled' => false],
            ],
            'compatiblePanelVersions' => [(string) config('app.version')],
            'capabilities' => [],
            'files' => [['path' => $path, 'sha256' => hash('sha256', self::BLOCKED_SOURCE)]],
        ];

        $archive = $this->workspace . '/demo-1.1.0.M12LabsExtension';
        $zip = new \ZipArchive();
        $zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString(ExtensionPackageArtifactService::MANIFEST_FILENAME, json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->addFromString($path, self::BLOCKED_SOURCE);
        $zip->close();

        return $archive;
    }
}
