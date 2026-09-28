<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Illuminate\Support\Facades\File;
use Everest\Exceptions\DisplayException;
use Everest\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\ExtensionBuildInputsService;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\ExtensionPanelRebuildService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;

/**
 * The frontend build used to glob every package directory on disk, so an
 * orphan directory -- or a file dropped into one after install -- was compiled
 * into the panel with no signature or database check. The build now takes an
 * allowlist of packages with a row and verified frontend files, and refuses to
 * run over tampered ones. In exchange, a request no longer hashes frontend
 * sources it never executes.
 */
class ExtensionBuildInputsTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    private const ORIGINAL = "export const Widget = () => null;\n";

    public function setUp(): void
    {
        parent::setUp();

        config()->set('modules.extensions.enabled', true);
        config()->set('logging.default', 'null');
        ExtensionRuntimePlanService::flush();
    }

    public function tearDown(): void
    {
        ExtensionRuntimePlanService::flush();

        parent::tearDown();
    }

    private function frontendPath(string $id): string
    {
        return sprintf('frontend/src/extensions/packages/%s/components/Widget.tsx', $id);
    }

    private function install(string $id): ExtensionPackage
    {
        $package = ExtensionPackage::query()->create(array_merge(
            $this->signedRuntimePackageAttributes($id, new ExtensionCapabilitySet(clientRoutes: true), [
                sprintf('app/Extensions/Packages/%s/routes/client.php', $id) => "<?php\n",
                $this->frontendPath($id) => self::ORIGINAL,
            ]),
            ['state' => 'enabled'],
        ));
        ExtensionConfig::query()->create(['extension_id' => $id, 'enabled' => true]);
        File::ensureDirectoryExists(base_path('frontend/src/extensions'));

        return $package;
    }

    /** @return list<string> */
    private function allowlist(): array
    {
        return json_decode((string) File::get(base_path(ExtensionBuildInputsService::ALLOWLIST_PATH)), true)['ids'];
    }

    public function testAnOrphanDirectoryIsLeftOutOfTheBuild(): void
    {
        $this->install('build_fixture');
        File::ensureDirectoryExists(base_path('frontend/src/extensions/packages/orphan_fixture/slots'));
        File::put(base_path('frontend/src/extensions/packages/orphan_fixture/slots/Injected.tsx'), "export default () => null;\n");

        $this->assertSame(['build_fixture'], app(ExtensionBuildInputsService::class)->prepare());
        $this->assertSame(['build_fixture'], $this->allowlist());
    }

    public function testATamperedFrontendFileBlocksTheRebuild(): void
    {
        $this->install('build_fixture');
        File::put(base_path($this->frontendPath('build_fixture')), "export const Widget = () => fetch('//evil');\n");

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('build_fixture');

        // Refused before anything is cleared or built.
        app(ExtensionPanelRebuildService::class)->rebuild('test rebuild');
    }

    /**
     * Frontend sources are verified at build time instead, so a request does
     * not hash them -- and editing one does not quarantine the running package.
     */
    public function testTheRuntimeNoLongerHashesFrontendSources(): void
    {
        $this->install('build_fixture');
        File::put(base_path($this->frontendPath('build_fixture')), "export const Widget = () => 'changed';\n");

        $this->assertArrayHasKey('build_fixture', app(ExtensionRuntimePlanService::class)->plan());
        $this->assertSame('enabled', ExtensionPackage::query()->where('extension_id', 'build_fixture')->value('state'));
    }

    /**
     * An install places files before its row exists, and an update before its
     * row changes: the operation's own verified manifest is the reference.
     */
    public function testAnIncomingPackageIsCheckedAgainstTheManifestItArrivedWith(): void
    {
        $package = $this->install('build_fixture');
        $manifest = app(ExtensionManifestParser::class)->parse($package->manifest, 'build_fixture', '1.0.0');
        $service = app(ExtensionBuildInputsService::class);

        $this->assertSame(['build_fixture'], $service->prepare(incoming: ['build_fixture' => $manifest]));

        File::put(base_path($this->frontendPath('build_fixture')), "export const Widget = () => 'tampered in transit';\n");

        $this->expectException(DisplayException::class);
        $service->prepare(incoming: ['build_fixture' => $manifest]);
    }

    public function testAPackageBeingUninstalledIsLeftOut(): void
    {
        $this->install('build_fixture');
        File::delete(base_path($this->frontendPath('build_fixture')));

        $this->assertSame([], app(ExtensionBuildInputsService::class)->prepare(outgoing: ['build_fixture']));
    }
}
