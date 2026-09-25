<?php

namespace Everest\Tests\Unit\Services\Extensions\Manifest;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;

/**
 * `capabilities.nav.admin` names the one sidebar entry a package's admin pages
 * fold under. It carries label, icon and order only: where the entry sits is
 * the panel's and the operator's call, so a placement key is refused.
 */
class ExtensionNavCapabilityTest extends TestCase
{
    private ExtensionManifestParser $parser;

    public function setUp(): void
    {
        parent::setUp();

        $this->parser = new ExtensionManifestParser();
    }

    /**
     * @param array<string, mixed>|null $nav
     *
     * @return array<string, mixed>
     */
    private function manifest(?array $nav, bool $adminPage = true): array
    {
        $capabilities = [];
        if ($adminPage) {
            $capabilities['pages'] = ['admin' => [[
                'slug' => 'settings',
                'labelKey' => 'ext.demo.nav.settings',
                'icon' => 'bot',
                'category' => 'modules',
                'order' => 10,
            ]]];
        }
        if ($nav !== null) {
            $capabilities['nav'] = $nav;
        }

        return [
            'manifestVersion' => 3,
            'package' => ['id' => 'demo', 'version' => '1.0.0'],
            'extension' => ['id' => 'demo', 'name' => 'Demo', 'description' => 'A demo package.', 'icon' => 'puzzle'],
            'compatiblePanelVersions' => ['>=Alpha 4.0 <Alpha 5.0'],
            'capabilities' => $capabilities,
            'files' => $adminPage
                ? [['path' => 'frontend/src/extensions/packages/demo/pages/admin/settings.tsx', 'sha256' => str_repeat('a', 64)]]
                : [['path' => 'app/Extensions/Packages/demo/routes/client.php', 'sha256' => str_repeat('a', 64)]],
        ];
    }

    public function testADeclaredEntryIsParsed(): void
    {
        $capabilities = $this->parser->parse($this->manifest([
            'admin' => ['labelKey' => 'ext.demo.nav.group', 'icon' => 'sparkles', 'order' => 20],
        ]))->capabilities;

        $this->assertNotNull($capabilities->adminNav);
        $this->assertSame(
            ['labelKey' => 'ext.demo.nav.group', 'icon' => 'sparkles', 'order' => 20],
            $capabilities->jsonSerialize()['nav']['admin'],
        );
    }

    /**
     * Every package installed before `nav` existed has its hash stored. An
     * unconditional key would change all of them and take every package inert.
     */
    public function testAManifestWithoutAnEntryKeepsItsProjectionAndHash(): void
    {
        $capabilities = $this->parser->parse($this->manifest(null))->capabilities;

        $this->assertNull($capabilities->adminNav);
        $this->assertArrayNotHasKey('nav', $capabilities->jsonSerialize());
    }

    public static function invalidEntries(): iterable
    {
        yield 'a placement key' => [['admin' => ['labelKey' => 'ext.demo.nav.group', 'icon' => 'bot', 'category' => 'operations']], '/Unknown key|not allowed|category/i'];
        yield 'another package\'s label' => [['admin' => ['labelKey' => 'ext.other.nav.group', 'icon' => 'bot']], '/must start with "ext\.demo\."/'];
        yield 'an unapproved icon' => [['admin' => ['labelKey' => 'ext.demo.nav.group', 'icon' => 'skull-and-bones']], '/approved icon set/'];
        yield 'a fractional order' => [['admin' => ['labelKey' => 'ext.demo.nav.group', 'icon' => 'bot', 'order' => 1.5]], '/order must be a whole number/'];
        yield 'an order out of range' => [['admin' => ['labelKey' => 'ext.demo.nav.group', 'icon' => 'bot', 'order' => 5000]], '/order must be a whole number/'];
        yield 'a list' => [[['labelKey' => 'ext.demo.nav.group']], '/must be an object/'];
        yield 'a server entry' => [['server' => ['labelKey' => 'ext.demo.nav.group', 'icon' => 'bot']], '/Unknown key|not allowed|server/i'];
    }

    /** @param array<string, mixed> $nav */
    #[DataProvider('invalidEntries')]
    public function testInvalidEntriesAreRefused(array $nav, string $message): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessageMatches($message);

        $this->parser->parse($this->manifest($nav));
    }

    public function testAnEntryNeedsAdminPagesUnderIt(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessageMatches('/needs at least one entry in capabilities\.pages\.admin/');

        $this->parser->parse($this->manifest(['admin' => ['labelKey' => 'ext.demo.nav.group', 'icon' => 'bot']], adminPage: false));
    }

    /**
     * The runtime rebuilds the set from the stored projection and compares
     * hashes; an entry lost on the way back would make the package inert.
     */
    public function testTheEntrySurvivesTheStoredProjectionRoundTrip(): void
    {
        $capabilities = $this->parser->parse($this->manifest([
            'admin' => ['labelKey' => 'ext.demo.nav.group', 'icon' => 'bot', 'order' => 5],
        ]))->capabilities;

        $hydrated = app(ExtensionRuntimePlanService::class)->hydrateCapabilities(
            json_decode(json_encode($capabilities->jsonSerialize()), true),
        );

        $this->assertNotNull($hydrated);
        $this->assertSame($capabilities->hash(), $hydrated->hash());
    }

    public function testAnEntryEditedInTheDatabaseDropsOutAndChangesTheHash(): void
    {
        $capabilities = $this->parser->parse($this->manifest([
            'admin' => ['labelKey' => 'ext.demo.nav.group', 'icon' => 'bot', 'order' => 5],
        ]))->capabilities;

        $projection = json_decode(json_encode($capabilities->jsonSerialize()), true);
        $projection['nav']['admin']['icon'] = 'javascript:alert(1)';

        $hydrated = app(ExtensionRuntimePlanService::class)->hydrateCapabilities($projection);

        $this->assertNotNull($hydrated);
        $this->assertNull($hydrated->adminNav);
        $this->assertNotSame($capabilities->hash(), $hydrated->hash());
    }
}
