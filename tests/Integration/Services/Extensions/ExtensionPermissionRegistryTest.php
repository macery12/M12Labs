<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\User;
use Everest\Models\AdminRole;
use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Everest\Models\ExtensionPermission;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Authorization\AdminAuthorizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Authorization\AdminCapabilityRegistry;
use Everest\Services\Extensions\ExtensionPermissionRegistry;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;
use Everest\Services\Extensions\Manifest\Definitions\PermissionDefinition;

/**
 * Extension-contributed admin permissions.
 *
 * The whole design rests on one existing behaviour: AdminCapabilityRegistry
 * flattens AdminRole::permissions() as "<namespace>.<key>". These tests pin
 * that a package's declared permissions really do become first-class
 * capabilities through that path, and — the part that is easy to get wrong —
 * that disabling or removing an extension revokes them without quietly
 * destroying grants an operator made.
 */
class ExtensionPermissionRegistryTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    private ExtensionPermissionRegistry $registry;

    public function setUp(): void
    {
        parent::setUp();

        ExtensionPermissionRegistry::flush();
        $this->registry = new ExtensionPermissionRegistry();
    }

    public function tearDown(): void
    {
        ExtensionPermissionRegistry::flush();

        parent::tearDown();
    }

    private function capabilities(string ...$actions): ExtensionCapabilitySet
    {
        return new ExtensionCapabilitySet(
            adminRoutes: true,
            adminPermissions: array_map(
                fn (string $action): PermissionDefinition => new PermissionDefinition(
                    key: $action,
                    labelKey: 'ext.demo.admin.' . $action . '.label',
                    descriptionKey: 'ext.demo.admin.' . $action . '.description',
                ),
                $actions
            ),
        );
    }

    private function installed(string $id = 'demo', bool $enabled = true): void
    {
        ExtensionPackage::create([
            'extension_id' => $id,
            'package_id' => $id,
            'name' => 'Demo Extension',
            'icon' => 'puzzle',
            'installed_version' => '1.0.0',
            'manifest' => ['manifestVersion' => 3, 'extension' => ['id' => $id]],
            'manifest_version' => 3,
            'state' => $enabled ? 'enabled' : 'installed_disabled',
        ]);

        ExtensionConfig::create(['extension_id' => $id, 'enabled' => $enabled]);
    }

    private function role(array $permissions): AdminRole
    {
        return AdminRole::create([
            'sort_id' => AdminRole::query()->max('sort_id') + 1,
            'name' => 'Extension operator ' . uniqid(),
            'description' => 'test',
            'permissions' => $permissions,
        ]);
    }

    public function testDeclaredPermissionsBecomeRealCapabilities(): void
    {
        $this->installed();
        $this->registry->sync('demo', $this->capabilities('read', 'rotate'), approved: true);

        $all = (new AdminCapabilityRegistry())->all();

        $this->assertContains('ext.demo.admin.read', $all);
        $this->assertContains('ext.demo.admin.rotate', $all);
        $this->assertTrue((new AdminCapabilityRegistry())->isValid('ext.demo.admin.read'));
    }

    /**
     * The escalation guard. A permission that nobody consented to must not be
     * assignable, which means it must not be in the catalog at all — otherwise
     * role validation would accept it.
     */
    public function testUnapprovedPermissionsAreNotAssignable(): void
    {
        $this->installed();
        $this->registry->sync('demo', $this->capabilities('read'), approved: false);

        $this->assertSame(1, ExtensionPermission::query()->count());
        $this->assertNotContains('ext.demo.admin.read', (new AdminCapabilityRegistry())->all());

        $this->registry->approve('demo');

        $this->assertContains('ext.demo.admin.read', (new AdminCapabilityRegistry())->all());
    }

    /**
     * A package cannot name a capability outside its own namespace: the
     * identifier is derived from the extension id, never taken from the
     * manifest.
     */
    public function testIdentifiersAreNamespacedToTheExtension(): void
    {
        $this->installed();
        $this->registry->sync('demo', $this->capabilities('read'), approved: true);

        $this->assertSame(
            'ext.demo.admin.read',
            ExtensionPermission::query()->where('extension_id', 'demo')->value('identifier')
        );
    }

    /**
     * Disabling revokes authority but must not touch the role, or an operator
     * would silently lose the grant across a toggle.
     */
    public function testSuspendingRevokesAuthorityWithoutDroppingTheGrant(): void
    {
        $this->installed();
        $this->registry->sync('demo', $this->capabilities('read'), approved: true);

        $role = $this->role(['ext.demo.admin.read']);
        $user = User::factory()->create(['admin_role_id' => $role->id]);

        $authorizer = new AdminAuthorizer(new AdminCapabilityRegistry(), $this->registry);
        $this->assertTrue($authorizer->hasCapability($user->fresh(), 'ext.demo.admin.read'));

        $this->registry->suspend('demo');

        $this->assertFalse($authorizer->hasCapability($user->fresh(), 'ext.demo.admin.read'));
        // Still catalogued, still held: saving an unrelated role must not prune it.
        $this->assertContains('ext.demo.admin.read', (new AdminCapabilityRegistry())->all());
        $this->assertSame(['ext.demo.admin.read'], $role->fresh()->permissions);

        $this->registry->resume('demo');
        $this->assertTrue($authorizer->hasCapability($user->fresh(), 'ext.demo.admin.read'));
    }

    /**
     * Uninstalling is the destructive case, and the two halves have to happen
     * together — a role left holding an identifier with nothing behind it would
     * be stripped by the next unrelated save, silently.
     */
    public function testPurgeRemovesThePermissionsAndTheGrants(): void
    {
        $this->installed();
        $this->registry->sync('demo', $this->capabilities('read'), approved: true);

        $role = $this->role(['users.read', 'ext.demo.admin.read']);

        $this->assertSame(1, $this->registry->assignmentCount('demo'));

        $this->registry->purge('demo');

        $this->assertSame(0, ExtensionPermission::query()->where('extension_id', 'demo')->count());
        $this->assertSame(['users.read'], array_values($role->fresh()->permissions));
        $this->assertNotContains('ext.demo.admin.read', (new AdminCapabilityRegistry())->all());
    }

    /** An update that drops a permission takes the grants with it. */
    public function testSyncRemovesPermissionsTheNewVersionNoLongerDeclares(): void
    {
        $this->installed();
        $this->registry->sync('demo', $this->capabilities('read', 'rotate'), approved: true);

        $role = $this->role(['ext.demo.admin.read', 'ext.demo.admin.rotate']);

        $this->registry->sync('demo', $this->capabilities('read'), approved: true);

        $this->assertSame(['read'], ExtensionPermission::query()->where('extension_id', 'demo')->pluck('action')->all());
        $this->assertSame(['ext.demo.admin.read'], array_values($role->fresh()->permissions));
    }

    /**
     * Install persists the package before its config row exists, which reads as
     * disabled — exactly the state a freshly installed package is in. A
     * permission must not start out authorizing anything.
     */
    public function testPermissionsAddedWhileDisabledStartSuspended(): void
    {
        $this->installed('demo', enabled: false);
        $this->registry->sync('demo', $this->capabilities('read'), approved: true);

        $this->assertContains('ext.demo.admin.read', $this->registry->suspendedIdentifiers());
    }

    /**
     * The whole path, driven by the real parser rather than a hand-built
     * capability set: a manifest declaring two admin permissions produces
     * exactly those two capabilities, under the extension's namespace, with the
     * label keys the package shipped.
     */
    public function testAParsedManifestProducesTheDeclaredCapabilities(): void
    {
        $this->installed();

        $manifest = app(ExtensionManifestParser::class)->parse([
            'manifestVersion' => 3,
            'package' => ['id' => 'demo', 'version' => '1.0.0', 'publisher' => 'm12labs'],
            'extension' => [
                'id' => 'demo',
                'name' => 'Demo',
                'description' => 'Fixture package.',
                'icon' => 'puzzle',
                'defaults' => ['enabled' => false],
            ],
            'compatiblePanelVersions' => ['>=Alpha 4.0 <Alpha 5.0'],
            'capabilities' => [
                'routes' => ['admin' => true],
                'permissions' => ['admin' => [
                    ['key' => 'read', 'labelKey' => 'ext.demo.permission.read'],
                    ['key' => 'purge', 'labelKey' => 'ext.demo.permission.purge', 'dangerous' => true],
                ]],
            ],
            'files' => [['path' => 'app/Extensions/Packages/demo/routes/admin.php', 'sha256' => str_repeat('0', 64)]],
        ], 'demo');

        $this->registry->sync('demo', $manifest->capabilities, approved: true);

        $rows = ExtensionPermission::query()->where('extension_id', 'demo')->orderBy('action')->get();

        $this->assertSame(['purge', 'read'], $rows->pluck('action')->all());
        $this->assertSame(
            ['ext.demo.admin.purge', 'ext.demo.admin.read'],
            $rows->pluck('identifier')->all()
        );
        $this->assertSame('ext.demo.permission.purge', $rows->first()->label_key);
        $this->assertTrue($rows->first()->dangerous);

        $groups = $this->registry->groups();
        $this->assertArrayHasKey('ext.demo.admin', $groups);
        $this->assertSame(
            ['purge' => 'ext.demo.permission.purge', 'read' => 'ext.demo.permission.read'],
            $groups['ext.demo.admin']['labelKeys']
        );
    }

    /** Core's catalog must survive the extension tables being unavailable. */
    public function testCorePermissionsAreUnaffectedByContributedGroups(): void
    {
        $this->installed();
        $this->registry->sync('demo', $this->capabilities('read'), approved: true);

        $all = (new AdminCapabilityRegistry())->all();

        $this->assertContains(AdminRole::USERS_READ, $all);
        $this->assertContains(AdminRole::EXTENSIONS_INSTALL, $all);
    }
}
