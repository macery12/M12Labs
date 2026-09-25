<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\User;
use Everest\Models\AdminRole;
use Everest\Models\ActivityLog;
use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionSecret;
use Everest\Models\ExtensionPackage;
use Everest\Extensions\Sdk\DisplayException;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Extensions\Sdk\Services\PackageSecrets;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\ExtensionPermissionRegistry;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\Definitions\SecretDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PermissionDefinition;

/**
 * A package taking a credential on its own settings page.
 *
 * What has to stay true is that every value in the store is one an
 * administrator allowed to manage extension credentials put there, and that
 * the audit trail reads the same whichever screen it was entered on.
 */
class PackageSecretsWriteTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public function setUp(): void
    {
        parent::setUp();

        ExtensionRuntimePlanService::flush();
        config()->set('modules.extensions.enabled', true);

        $capabilities = new ExtensionCapabilitySet(
            adminPermissions: [new PermissionDefinition('settings', 'ext.demo.permission.settings')],
            secrets: [new SecretDefinition(key: 'api_key', labelKey: 'ext.demo.secret.apiKey')],
        );

        ExtensionPackage::create(array_merge($this->signedRuntimePackageAttributes('demo', $capabilities), [
            'name' => 'Demo',
            'state' => 'enabled',
        ]));
        ExtensionConfig::create(['extension_id' => 'demo', 'enabled' => true]);
        // What install does once the capability diff is approved: the package's
        // admin permissions become assignable.
        app(ExtensionPermissionRegistry::class)->sync('demo', $capabilities, approved: true);
        ExtensionRuntimePlanService::flush();
    }

    public function tearDown(): void
    {
        ExtensionRuntimePlanService::flush();

        parent::tearDown();
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['admin_role_id' => AdminRole::query()->where('is_owner', true)->value('id')])->save();

        return $user->refresh();
    }

    /** @param array<int, string> $permissions */
    private function admin(array $permissions): User
    {
        $role = AdminRole::query()->create(['name' => 'Limited ' . uniqid(), 'sort_id' => 99, 'permissions' => $permissions]);

        $user = User::factory()->create();
        $user->forceFill(['admin_role_id' => $role->id])->save();

        return $user->refresh();
    }

    public function testAnAdministratorCanEnterACredentialFromAPackagePage(): void
    {
        $owner = $this->owner();

        PackageSecrets::for('demo')->put($owner, 'api_key', 'sk-live-123');

        $this->assertSame('sk-live-123', PackageSecrets::for('demo')->get('api_key'));
        $this->assertSame($owner->id, ExtensionSecret::query()->where('extension_id', 'demo')->value('updated_by'));

        $log = ActivityLog::query()->where('event', 'admin:extensions:secret-update')->latest('id')->firstOrFail();
        $this->assertSame('api_key', $log->properties['key']);
        $this->assertSame('demo', $log->properties['via']);
        $this->assertStringNotContainsString('sk-live-123', json_encode($log->properties));
    }

    public function testBlankLeavesTheStoredCredentialAlone(): void
    {
        $owner = $this->owner();
        PackageSecrets::for('demo')->put($owner, 'api_key', 'sk-live-123');

        PackageSecrets::for('demo')->put($owner, 'api_key', '   ');

        $this->assertSame('sk-live-123', PackageSecrets::for('demo')->get('api_key'));
    }

    public function testForgetRemovesItAndIsAudited(): void
    {
        $owner = $this->owner();
        PackageSecrets::for('demo')->put($owner, 'api_key', 'sk-live-123');

        PackageSecrets::for('demo')->forget($owner, 'api_key');

        $this->assertFalse(PackageSecrets::for('demo')->configured('api_key'));
        $this->assertTrue(ActivityLog::query()->where('event', 'admin:extensions:secret-delete')->exists());
    }

    /** A package page must not widen who may set a credential. */
    public function testAnAdministratorWithoutExtensionsUpdateIsRefused(): void
    {
        $reader = $this->admin([AdminRole::EXTENSIONS_READ]);

        try {
            PackageSecrets::for('demo')->put($reader, 'api_key', 'sk-live-123');
            $this->fail('A credential was written by an administrator without extensions.update.');
        } catch (DisplayException) {
        }

        $this->assertFalse(PackageSecrets::for('demo')->configured('api_key'));
    }

    public function testAnUndeclaredKeyIsRefused(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not declare a secret');

        PackageSecrets::for('demo')->put($this->owner(), 'other_key', 'value');
    }

    /**
     * An operator can grant "may configure this extension" without granting
     * "may install and remove every extension": the page names the permission
     * it is gated by, and that permission is enough.
     */
    public function testThePackagesOwnSettingsPermissionIsEnoughWhenThePageNamesIt(): void
    {
        $admin = $this->admin(['ext.demo.admin.settings']);

        PackageSecrets::for('demo')->put($admin, 'api_key', 'sk-live-456', 'ext.demo.admin.settings');

        $this->assertSame('sk-live-456', PackageSecrets::for('demo')->get('api_key'));
    }

    public function testThePackagesOwnPermissionCountsOnlyWhenThePageNamesIt(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('requires the extensions update permission');

        PackageSecrets::for('demo')->put($this->admin(['ext.demo.admin.settings']), 'api_key', 'sk-live-456');
    }

    /**
     * A page cannot widen who may write by naming a permission this package
     * never declared — another package's, or a core one.
     */
    public function testAPermissionThePackageDidNotDeclareIsRefused(): void
    {
        foreach (['ext.other.admin.settings', 'servers.read'] as $permission) {
            try {
                PackageSecrets::for('demo')->put($this->admin([$permission]), 'api_key', 'sk-live-789', $permission);
                $this->fail(sprintf('%s: expected a refusal.', $permission));
            } catch (DisplayException $exception) {
                $this->assertStringContainsString('does not declare the admin permission', $exception->getMessage());
            }
        }

        $this->assertNull(PackageSecrets::for('demo')->get('api_key'));
    }
}
