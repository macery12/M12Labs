<?php

namespace Everest\Tests\Unit\Services\Extensions\Manifest;

use Everest\Tests\TestCase;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityDiff;
use Everest\Services\Extensions\Manifest\Definitions\HookDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PageDefinition;
use Everest\Services\Extensions\Manifest\Definitions\SecretDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PermissionDefinition;

/**
 * An update must never silently widen what a package can do. The diff is what
 * the installer shows before asking an administrator to approve an escalation.
 */
class ExtensionCapabilityDiffTest extends TestCase
{
    public function testAFirstInstallCountsEveryPrivilegeAsNew(): void
    {
        $diff = ExtensionCapabilityDiff::between(null, new ExtensionCapabilitySet(clientRoutes: true));

        $this->assertContains('routes.client', $diff->added);
        $this->assertTrue($diff->isEscalation());
    }

    public function testAnUnchangedPackageProducesNoDiff(): void
    {
        $set = new ExtensionCapabilitySet(clientRoutes: true, migrations: true);

        $diff = ExtensionCapabilityDiff::between($set, $set);

        $this->assertTrue($diff->isEmpty());
        $this->assertFalse($diff->isEscalation());
    }

    /** A new permission, hook, queue or secret is exactly what needs consent. */
    public function testNewPrivilegedSurfacesAreEscalations(): void
    {
        $before = new ExtensionCapabilitySet(clientRoutes: true);
        $after = new ExtensionCapabilitySet(
            clientRoutes: true,
            adminPermissions: [new PermissionDefinition('delete', 'ext.demo.delete', dangerous: true)],
            hooks: [new HookDefinition('server.pre_delete', 'Cleanup', 'synchronous_best_effort')],
            secrets: [new SecretDefinition('api_token', 'ext.demo.token')],
        );

        $diff = ExtensionCapabilityDiff::between($before, $after);

        $this->assertTrue($diff->isEscalation());
        $this->assertContains('permission:delete (destructive)', $diff->escalations);
        $this->assertContains('hook:server.pre_delete -> Cleanup (synchronous_best_effort)', $diff->escalations);
        $this->assertContains('secret:api_token', $diff->escalations);
    }

    /**
     * Adding a page grants no reach the extension did not already have, so it
     * appears in the diff for review but does not force a re-approval.
     */
    public function testAddingAPageIsReportedButIsNotAnEscalation(): void
    {
        $before = new ExtensionCapabilitySet(clientRoutes: true);
        $after = new ExtensionCapabilitySet(
            clientRoutes: true,
            serverPages: [new PageDefinition('main', 'ext.demo.nav', 'globe', 'data', 100)],
        );

        $diff = ExtensionCapabilityDiff::between($before, $after);

        $this->assertContains('page.server:main', $diff->added);
        $this->assertFalse($diff->isEscalation());
    }

    /** Narrowing needs no consent. */
    public function testRemovingAPrivilegeIsNotAnEscalation(): void
    {
        $before = new ExtensionCapabilitySet(
            clientRoutes: true,
            adminPermissions: [new PermissionDefinition('delete', 'ext.demo.delete')],
        );
        $after = new ExtensionCapabilitySet(clientRoutes: true);

        $diff = ExtensionCapabilityDiff::between($before, $after);

        $this->assertContains('permission:delete', $diff->removed);
        $this->assertFalse($diff->isEscalation());
    }

    /**
     * Approval is carried as a hash, so it must change whenever the privileges
     * do — otherwise an approval for one set would authorise another.
     */
    public function testTheApprovalHashTracksTheCapabilities(): void
    {
        $a = new ExtensionCapabilitySet(clientRoutes: true);
        $b = new ExtensionCapabilitySet(clientRoutes: true, adminRoutes: true);

        $this->assertSame($a->hash(), ExtensionCapabilityDiff::between(null, $a)->hash);
        $this->assertNotSame($a->hash(), $b->hash());
    }

    /** A mode change is a different privilege, not the same one. */
    public function testChangingAHookDeliveryModeIsAnEscalation(): void
    {
        $before = new ExtensionCapabilitySet(hooks: [new HookDefinition('server.created', 'Sync', 'queued_at_least_once')]);
        $after = new ExtensionCapabilitySet(hooks: [new HookDefinition('server.created', 'Sync', 'synchronous_best_effort')]);

        $diff = ExtensionCapabilityDiff::between($before, $after);

        $this->assertTrue($diff->isEscalation());
    }
}
