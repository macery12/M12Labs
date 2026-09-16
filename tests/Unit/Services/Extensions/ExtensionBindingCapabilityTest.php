<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionRuntimeEntry;
use Everest\Services\Extensions\ExtensionBindingRegistrar;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityDiff;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;

/**
 * `capabilities.bindings` — a package asking the container to build one of its
 * own classes once per request rather than on every resolution.
 *
 * Data rather than a service provider, and that is the whole design. A provider
 * would run package code on every request, including the overwhelming majority
 * with nothing to do with that extension; a route or a job runs only when
 * something reaches it. Keeping the declaration declarative means the feature
 * costs an array write at boot and executes nothing.
 *
 * So the cases here are about the two things that keeps honest: a package can
 * only ever name its own classes, and the string it names cannot become
 * anything but a class inside its own directory.
 */
class ExtensionBindingCapabilityTest extends TestCase
{
    private ExtensionManifestParser $parser;

    public function setUp(): void
    {
        parent::setUp();

        $this->parser = new ExtensionManifestParser();
    }

    /**
     * @param array<int, string> $bindings
     * @param array<int, string> $files
     *
     * @return array<string, mixed>
     */
    private function manifest(array $bindings, array $files = []): array
    {
        return [
            'manifestVersion' => 3,
            'package' => ['id' => 'demo', 'version' => '1.0.0'],
            'extension' => [
                'id' => 'demo',
                'name' => 'Demo',
                'description' => 'A demo package.',
                'icon' => 'puzzle',
                'defaults' => ['enabled' => false, 'allowedNests' => [], 'allowedEggs' => [], 'settings' => []],
            ],
            'compatiblePanelVersions' => ['>=Alpha 4.1 <Alpha 5.0'],
            'capabilities' => ['bindings' => $bindings],
            'files' => array_map(
                fn (string $path): array => ['path' => $path, 'sha256' => str_repeat('a', 64)],
                $files === [] ? ['app/Extensions/Packages/demo/routes/client.php'] : $files,
            ),
        ];
    }

    public function testADeclaredClassIsParsed(): void
    {
        $manifest = $this->parser->parse($this->manifest(['Tools/ToolCatalogue']));

        $this->assertSame(['Tools/ToolCatalogue'], $manifest->capabilities->bindings);
    }

    /**
     * The property the whole design rests on: the class name is *derived* from
     * the declaration and the extension id, so there is no spelling that reaches
     * a core service or another extension's. Nothing has to check, because
     * nothing else is expressible.
     */
    public function testADeclarationCanOnlyEverNameAClassInsideThePackage(): void
    {
        $this->assertSame(
            'Everest\\Extensions\\Packages\\demo\\Tools\\ToolCatalogue',
            ExtensionBindingRegistrar::classFor('demo', 'Tools/ToolCatalogue'),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedPaths')]
    public function testAPathThatCouldEscapeThePackageIsRefused(string $path): void
    {
        $this->expectException(DisplayException::class);

        $this->parser->parse($this->manifest([$path]));
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedPaths(): array
    {
        return [
            'traversal' => ['../../Services/Setting'],
            'leading slash' => ['/Tools/ToolCatalogue'],
            'fully qualified' => ['Everest\\Services\\AI\\Tools\\ToolCatalogue'],
            'lowercase segment' => ['tools/ToolCatalogue'],
            'file extension' => ['Tools/ToolCatalogue.php'],
            'empty' => [''],
            'trailing slash' => ['Tools/'],
        ];
    }

    /**
     * Spelling a core class's path does not reach it — the derived name is
     * still inside the package, it just names something that is not there, and
     * the file rule then refuses it for not being shipped. This is what "cannot
     * escape by construction" means in practice: not that the string is
     * rejected, but that there is nowhere else for it to land.
     */
    public function testNamingACoreServicesPathStillLandsInsideThePackage(): void
    {
        $this->assertSame(
            'Everest\\Extensions\\Packages\\demo\\Everest\\Services\\Access\\DelegatedAccess',
            ExtensionBindingRegistrar::classFor('demo', 'Everest/Services/Access/DelegatedAccess'),
        );
    }

    public function testTheProjectionDoesNotDependOnTheAuthorsOrdering(): void
    {
        $a = $this->parser->parse($this->manifest(['Tools/B', 'Tools/A']));
        $b = $this->parser->parse($this->manifest(['Tools/A', 'Tools/B', 'Tools/A']));

        $this->assertSame(['Tools/A', 'Tools/B'], $a->capabilities->bindings);
        $this->assertSame($a->capabilities->hash(), $b->capabilities->hash());
    }

    /**
     * The same compatibility trap as `capabilities.privileged`: this projection
     * is what `capability_hash` covers, and the runtime plan re-hashes the
     * stored copy on every build. An unconditional key would take every
     * already-installed package inert on upgrade.
     */
    public function testAPackageDeclaringNoneProjectsExactlyAsItDidBefore(): void
    {
        $set = new ExtensionCapabilitySet();

        $this->assertArrayNotHasKey('bindings', $set->jsonSerialize());
        $this->assertSame('8345c562738d013091735923ac4f048d9eafebd2b714531438b23b2b6b6a0025', $set->hash());
    }

    /**
     * Making one of a package's own classes shared reaches nothing the package
     * could not already reach, so it is shown to an administrator without
     * demanding a fresh approval — the same treatment a settings field gets.
     */
    public function testAddingOneIsInformationalRatherThanAnEscalation(): void
    {
        $diff = ExtensionCapabilityDiff::between(
            new ExtensionCapabilitySet(clientRoutes: true),
            new ExtensionCapabilitySet(clientRoutes: true, bindings: ['Tools/ToolCatalogue']),
        );

        $this->assertSame(['binding:Tools/ToolCatalogue'], $diff->added);
        $this->assertSame([], $diff->escalations);
        $this->assertFalse($diff->isEscalation());
    }

    public function testTheRegistrarBindsOnlyDeclaredClassesAndBindsThemShared(): void
    {
        $plan = \Mockery::mock(ExtensionRuntimePlanService::class);
        $plan->shouldReceive('withCapability')->with('bindings')->andReturn([
            'demo' => new ExtensionRuntimeEntry(
                'demo',
                '1.0.0',
                new ExtensionCapabilitySet(bindings: ['Tools/ToolCatalogue']),
            ),
        ]);

        (new ExtensionBindingRegistrar($this->app, $plan))->register();

        $this->assertTrue($this->app->bound('Everest\\Extensions\\Packages\\demo\\Tools\\ToolCatalogue'));
        $this->assertTrue($this->app->isShared('Everest\\Extensions\\Packages\\demo\\Tools\\ToolCatalogue'));
        $this->assertFalse($this->app->bound('Everest\\Extensions\\Packages\\demo\\Tools\\Other'));
    }

    /**
     * The projection is the one part of an installed package somebody with
     * database access could edit, and this value becomes a class name — so the
     * pattern is applied on the way out of storage, not only on the way in.
     */
    public function testAProjectionEditedInTheDatabaseCannotSmuggleAClassName(): void
    {
        $hydrated = app(ExtensionRuntimePlanService::class)->hydrateCapabilities([
            'routes' => ['client' => false, 'admin' => false],
            'bindings' => ['Tools/Fine', '../../../Services/Access/DelegatedAccess', 'Everest\\Models\\User'],
        ]);

        $this->assertNotNull($hydrated);
        $this->assertSame(['Tools/Fine'], $hydrated->bindings);
    }

    public function testADeclaredBindingMustShipItsClass(): void
    {
        $manifest = $this->parser->parse($this->manifest(
            ['Tools/ToolCatalogue'],
            ['app/Extensions/Packages/demo/Tools/SomethingElse.php'],
        ));

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessageMatches('/does not ship app\/Extensions\/Packages\/demo\/Tools\/ToolCatalogue\.php/');

        app(\Everest\Services\Extensions\Manifest\ExtensionCapabilityFileValidator::class)
            ->assertMatchesFiles($manifest);
    }

    /**
     * The reverse is deliberately not a fault: a package ships plenty of classes
     * it has no reason to share, and requiring a declaration for each would turn
     * an optimisation into paperwork.
     */
    public function testAnUndeclaredClassIsNotAFault(): void
    {
        $manifest = $this->parser->parse($this->manifest(
            ['Tools/ToolCatalogue'],
            [
                'app/Extensions/Packages/demo/Tools/ToolCatalogue.php',
                'app/Extensions/Packages/demo/Tools/SomethingElse.php',
            ],
        ));

        app(\Everest\Services\Extensions\Manifest\ExtensionCapabilityFileValidator::class)
            ->assertMatchesFiles($manifest);

        $this->assertSame(['Tools/ToolCatalogue'], $manifest->capabilities->bindings);
    }
}
