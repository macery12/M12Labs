<?php

namespace Everest\Tests\Unit\Services\Extensions\Manifest;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;

/**
 * The parser is the boundary that decides what an installed package may do.
 *
 * Two invariants drive these cases: a capability the manifest does not declare
 * is denied rather than inferred, and an unrecognised key is rejected rather
 * than ignored — so a manifest written for a different panel fails loudly
 * instead of installing with a gate silently missing.
 */
class ExtensionManifestParserTest extends TestCase
{
    private ExtensionManifestParser $parser;

    public function setUp(): void
    {
        parent::setUp();

        $this->parser = new ExtensionManifestParser();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function manifest(array $overrides = []): array
    {
        return array_replace_recursive([
            'manifestVersion' => 3,
            'package' => ['id' => 'demo', 'version' => '1.0.0'],
            'extension' => [
                'id' => 'demo',
                'name' => 'Demo',
                'description' => 'A demo package.',
                'icon' => 'puzzle',
                'defaults' => ['enabled' => false, 'allowedNests' => [], 'allowedEggs' => [], 'settings' => []],
            ],
            'compatiblePanelVersions' => ['>=Alpha 4.0 <Alpha 5.0'],
            'capabilities' => [],
            'files' => [['path' => 'app/Extensions/Packages/demo/routes/client.php', 'sha256' => str_repeat('a', 64)]],
        ], $overrides);
    }

    public function testParsesAMinimalManifest(): void
    {
        $manifest = $this->parser->parse($this->manifest());

        $this->assertSame('demo', $manifest->id);
        $this->assertSame('1.0.0', $manifest->version);
        $this->assertFalse($manifest->capabilities->clientRoutes);
        $this->assertFalse($manifest->defaultEnabled());
    }

    /**
     * v1 and v2 manifests carry no capability block at all, so every surface
     * would have to be inferred from files — exactly the behaviour v3 replaces.
     * They are rejected rather than shimmed.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function unsupportedManifestVersions(): array
    {
        return [
            'v1 (implicit)' => [1],
            'v2' => [2],
            'built for a newer panel' => [4],
            'missing' => [null],
            'string instead of int' => ['3'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedManifestVersions')]
    public function testRejectsEveryManifestVersionExceptThree(mixed $version): void
    {
        $manifest = $this->manifest();
        $manifest['manifestVersion'] = $version;

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessageMatches('/requires manifest version 3/');

        $this->parser->parse($manifest);
    }

    public function testRejectsUnknownTopLevelKeys(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('unknown key(s): sneaky');

        $this->parser->parse($this->manifest(['sneaky' => true]));
    }

    /** An unknown capability must not be silently dropped into "denied". */
    public function testRejectsUnknownCapabilityKeys(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('unknown key(s): filesystem');

        $this->parser->parse($this->manifest(['capabilities' => ['filesystem' => true]]));
    }

    public function testRejectsIdentityMismatchBetweenPackageAndExtension(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not match the extension id');

        $this->parser->parse($this->manifest(['package' => ['id' => 'other']]));
    }

    public function testRejectsAPackageClaimingADifferentRequestedId(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not match the requested extension id');

        $this->parser->parse($this->manifest(), 'something_else');
    }

    public function testRejectsAnIconOutsideTheApprovedSet(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('not in the approved icon set');

        $this->parser->parse($this->manifest(['extension' => ['icon' => 'skull']]));
    }

    // ------------------------------------------------------------ namespacing

    /**
     * Table, command and translation prefixes are what keep two packages — and
     * a package and core — from claiming the same storage, artisan name or
     * string. Each is derived from the id, never taken from the manifest.
     */
    public function testRejectsATableOutsideThePackagePrefix(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('must start with "ext_demo_"');

        $this->parser->parse($this->manifest([
            'capabilities' => ['database' => ['migrations' => true, 'tables' => ['servers']]],
        ]));
    }

    public function testRejectsACommandOutsideThePackagePrefix(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('must start with "p:ext:demo:"');

        $this->parser->parse($this->manifest([
            'capabilities' => ['commands' => ['p:extensions:uninstall']],
        ]));
    }

    public function testRejectsATranslationKeyOutsideThePackageNamespace(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('must start with "ext.demo."');

        $this->parser->parse($this->manifest([
            'capabilities' => ['permissions' => ['admin' => [
                ['key' => 'read', 'labelKey' => 'admin.servers.delete'],
            ]]],
        ]));
    }

    // ------------------------------------------------------------- permissions

    public function testParsesAdminPermissionsAndDerivesTheirIdentifiers(): void
    {
        $manifest = $this->parser->parse($this->manifest([
            'capabilities' => ['permissions' => ['admin' => [
                ['key' => 'read', 'labelKey' => 'ext.demo.permission.read'],
                ['key' => 'delete', 'labelKey' => 'ext.demo.permission.delete', 'dangerous' => true],
            ]]],
        ]));

        $permissions = $manifest->capabilities->adminPermissions;
        $this->assertCount(2, $permissions);
        $this->assertSame('ext.demo.admin.read', $permissions[0]->identifier('demo'));
        $this->assertTrue($permissions[1]->dangerous);
    }

    public function testRejectsDuplicatePermissionKeys(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('Duplicate admin permission "read"');

        $this->parser->parse($this->manifest([
            'capabilities' => ['permissions' => ['admin' => [
                ['key' => 'read', 'labelKey' => 'ext.demo.a'],
                ['key' => 'read', 'labelKey' => 'ext.demo.b'],
            ]]],
        ]));
    }

    /** A page gated on an undeclared permission would be permanently unreachable. */
    public function testRejectsAnAdminPageGatedOnAnUndeclaredPermission(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('which the manifest does not declare');

        $this->parser->parse($this->manifest([
            'capabilities' => ['pages' => ['admin' => [[
                'slug' => 'main',
                'labelKey' => 'ext.demo.nav',
                'icon' => 'globe',
                'category' => 'modules',
                'requiredExtensionPermission' => 'read',
            ]]]],
        ]));
    }

    // ------------------------------------------------------------------- hooks

    /**
     * PHP cannot preempt a handler, so a declared timeout is a budget, not an
     * enforcement — an in-process hook able to abort server deletion has no
     * bounded blast radius. The mode is recognised only so it can be refused
     * with an explanation.
     */
    public function testRejectsSynchronousRequiredHooksWithAnExplanation(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('has no bounded failure');

        $this->parser->parse($this->manifest([
            'capabilities' => ['hooks' => [[
                'event' => 'server.pre_delete',
                'handler' => 'Cleanup',
                'mode' => 'synchronous_required',
            ]]],
        ]));
    }

    public function testRejectsAnUndocumentedHookEvent(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('not a documented extension event');

        $this->parser->parse($this->manifest([
            'capabilities' => ['hooks' => [[
                'event' => 'user.password_changed',
                'handler' => 'Steal',
                'mode' => 'queued_at_least_once',
            ]]],
        ]));
    }

    public function testRejectsAHandlerNameThatEscapesThePackageNamespace(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('invalid handler class name');

        $this->parser->parse($this->manifest([
            'capabilities' => ['hooks' => [[
                'event' => 'server.created',
                'handler' => 'Everest\\Services\\Servers\\ServerDeletionService',
                'mode' => 'queued_at_least_once',
            ]]],
        ]));
    }

    public function testDerivesTheHookHandlerClassFromThePackageNamespace(): void
    {
        $manifest = $this->parser->parse($this->manifest([
            'capabilities' => ['hooks' => [[
                'event' => 'server.pre_delete',
                'handler' => 'CleanupServerDomains',
                'mode' => 'synchronous_best_effort',
                'timeoutMs' => 3000,
            ]]],
        ]));

        $this->assertSame(
            'Everest\\Extensions\\Packages\\demo\\Hooks\\CleanupServerDomains',
            $manifest->capabilities->hooks[0]->handlerClass('demo')
        );
    }

    // ----------------------------------------------------- settings and secrets

    /**
     * extension_configs.settings is a plain JSON column the catalog API returns,
     * so a "password" field there would be readable by anyone who can list
     * extensions. Secrets live in the encrypted store instead.
     */
    public function testRejectsPasswordSettingsAndPointsAtTheSecretStore(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('capabilities.secrets instead');

        $this->parser->parse($this->manifest([
            'capabilities' => ['settings' => ['fields' => [
                ['key' => 'token', 'type' => 'password', 'labelKey' => 'ext.demo.token'],
            ]]],
        ]));
    }

    /** A shipped default would put the credential in the archive and registry. */
    public function testRejectsASecretThatShipsADefault(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('never shipped in a package');

        $this->parser->parse($this->manifest([
            'capabilities' => ['secrets' => [
                ['key' => 'api_token', 'labelKey' => 'ext.demo.token', 'default' => 'hunter2'],
            ]],
        ]));
    }

    public function testRejectsADefaultForAnUndeclaredSettingKey(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('not a declared setting field');

        $this->parser->parse($this->manifest([
            'extension' => ['defaults' => ['settings' => ['typoed_key' => 1]]],
        ]));
    }

    /** Bounded so an administrator's input cannot trigger catastrophic backtracking. */
    public function testRejectsRecursiveSettingPatterns(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('recursive or back-referencing construct');

        $this->parser->parse($this->manifest([
            'capabilities' => ['settings' => ['fields' => [
                ['key' => 'name', 'type' => 'text', 'labelKey' => 'ext.demo.name', 'pattern' => '^(a(?R)?)$'],
            ]]],
        ]));
    }

    // ------------------------------------------------------------ requirements

    public function testRejectsCrossExtensionDependencies(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('Extension-to-extension dependencies are not supported');

        $this->parser->parse($this->manifest([
            'requirements' => ['extensions' => ['other_package']],
        ]));
    }

    public function testRejectsAnUndeclaredPanelService(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('requirements.panelServices names "filesystem"');

        $this->parser->parse($this->manifest([
            'requirements' => ['panelServices' => ['filesystem']],
        ]));
    }

    // ------------------------------------------------------------------- files

    public function testRejectsAManifestWithoutACompatibilityDeclaration(): void
    {
        $manifest = $this->manifest();
        $manifest['compatiblePanelVersions'] = [];

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('at least one entry in "compatiblePanelVersions"');

        $this->parser->parse($manifest);
    }

    public function testRejectsDuplicateFileEntries(): void
    {
        $manifest = $this->manifest();
        $manifest['files'] = [
            ['path' => 'app/Extensions/Packages/demo/routes/client.php', 'sha256' => str_repeat('a', 64)],
            ['path' => 'app/Extensions/Packages/demo/routes/client.php', 'sha256' => str_repeat('b', 64)],
        ];

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('more than once');

        $this->parser->parse($manifest);
    }

    public function testRejectsThePanelGeneratedPageManifestAsAPackageFile(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('reserved panel-generated file');

        $this->parser->parse($this->manifest([
            'files' => [[
                'path' => 'frontend/src/extensions/packages/demo/extension.pages.json',
                'sha256' => str_repeat('a', 64),
            ]],
        ]));
    }
}
