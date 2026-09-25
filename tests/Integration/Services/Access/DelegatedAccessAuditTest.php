<?php

namespace Everest\Tests\Integration\Services\Access;

use Everest\Models\User;
use Everest\Models\Server;
use Everest\Models\AdminRole;
use Everest\Models\ActivityLog;
use Everest\Services\Access\DelegatedGrant;
use Everest\Services\Access\DelegatedAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Everest\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

/**
 * Delegated access is only ever used while the customer-visible row that
 * recorded it exists and describes it.
 *
 * The unit tests cover the shape of a grant; these cover the one property that
 * needs the database: that a grant value on its own — built by hand, restored
 * from storage, or pointed at somebody else's record — opens nothing, and that
 * no operator setting can make the record disappear from the customer's feed.
 */
class DelegatedAccessAuditTest extends ClientApiIntegrationTestCase
{
    private function access(): DelegatedAccess
    {
        return app(DelegatedAccess::class);
    }

    /** @param array<int, string> $permissions */
    private function staff(array $permissions = [AdminRole::SERVERS_ASSIST]): User
    {
        $role = AdminRole::query()->create(['name' => 'Support ' . uniqid(), 'sort_id' => 99, 'permissions' => $permissions]);

        $user = User::factory()->create();
        $user->forceFill(['admin_role_id' => $role->id])->save();

        return $user->refresh();
    }

    private function ownerAdmin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['admin_role_id' => AdminRole::query()->where('is_owner', true)->value('id')])->save();

        return $user->refresh();
    }

    public function testOpeningWritesTheRowTheGrantIsSealedTo(): void
    {
        $admin = $this->staff();
        $server = $this->createServerModel();

        $grant = $this->access()->open($admin, $server, 'Ticket #4: will not boot', 4, onBehalfOf: 'ai');

        $row = ActivityLog::query()->findOrFail($grant->auditId);
        $this->assertSame(ActivityLog::EVENT_DELEGATED_ACCESS_START, $row->event);
        $this->assertSame($server->id, $row->server_id);
        $this->assertSame($admin->id, $row->actor_id);
        $this->assertSame(DelegatedGrant::READ_ABILITIES, $row->properties->get('abilities'));
        $this->assertSame('ai', $row->properties->get('via'));
    }

    public function testASealedGrantRunsAndSurvivesStorage(): void
    {
        $admin = $this->staff();
        $server = $this->createServerModel();

        $grant = $this->access()->open($admin, $server, 'why');
        $restored = DelegatedGrant::fromArray(json_decode(json_encode($grant->toArray()), true));

        $this->assertNotNull($restored);
        $this->assertSame('ran', $this->access()->during($admin, $restored, fn () => 'ran'));
    }

    /**
     * Operators may switch activity logging off. They may not switch off the
     * customer's view of staff entering their server.
     */
    public function testTheRowIsWrittenEvenWithActivityLoggingSwitchedOff(): void
    {
        config()->set('activity.enabled.account', false);
        config()->set('activity.enabled.admin', false);
        config()->set('activity.enabled.server', false);

        $grant = $this->access()->open($this->staff(), $this->createServerModel(), 'why');

        $this->assertTrue(ActivityLog::query()->whereKey($grant->auditId)->exists());
    }

    public function testAHandBuiltGrantOpensNothing(): void
    {
        $admin = $this->staff();
        $server = $this->createServerModel();

        $this->expectException(AuthorizationException::class);

        $this->access()->during(
            $admin,
            DelegatedGrant::read($server->uuid, (string) $server->name, 'forged')->escalated(),
            fn () => $this->fail('An unrecorded grant must not run.'),
        );
    }

    /**
     * A seal is checked against what the row says, so pointing a grant at a
     * genuine record of something else gains nothing: another server, another
     * administrator, or a narrower level all refuse.
     */
    public function testASealBorrowedFromAnotherRecordIsRefused(): void
    {
        $admin = $this->staff();
        $server = $this->createServerModel();
        $other = $this->createServerModel();

        $genuine = $this->access()->open($admin, $server, 'why');

        $cases = [
            'another server' => [$admin, DelegatedGrant::read($other->uuid, (string) $other->name, 'why')->sealedTo($genuine->auditId)],
            'another administrator' => [$this->staff(), $genuine],
            'a wider level' => [$admin, $genuine->escalated()->sealedTo($genuine->auditId)],
        ];

        foreach ($cases as $label => [$actor, $grant]) {
            try {
                $this->access()->during($actor, $grant, fn () => $this->fail(sprintf('%s: must not run.', $label)));
                $this->fail(sprintf('%s: expected a refusal.', $label));
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEditingTheRecordedAbilitiesRevokesTheGrant(): void
    {
        $admin = $this->staff();
        $grant = $this->access()->open($admin, $this->createServerModel(), 'why');

        $row = ActivityLog::query()->findOrFail($grant->auditId);
        $row->properties = $row->properties->put('abilities', ['file.read']);
        $row->save();

        $this->expectException(AuthorizationException::class);

        $this->access()->during($admin, $grant, fn () => $this->fail('must not run'));
    }

    public function testDeletingTheRecordRevokesTheGrant(): void
    {
        $admin = $this->staff();
        $grant = $this->access()->open($admin, $this->createServerModel(), 'why');

        ActivityLog::query()->whereKey($grant->auditId)->delete();

        $this->expectException(AuthorizationException::class);

        $this->access()->during($admin, $grant, fn () => $this->fail('must not run'));
    }

    public function testEscalationWritesItsOwnRowAndSealsToIt(): void
    {
        $admin = $this->staff();
        $server = $this->createServerModel();

        $read = $this->access()->open($admin, $server, 'why');
        $write = $this->access()->escalate($admin, $server, $read);

        $this->assertTrue($write->writable);
        $this->assertNotSame($read->auditId, $write->auditId);
        $this->assertSame(
            ActivityLog::EVENT_DELEGATED_ACCESS_ESCALATE,
            ActivityLog::query()->findOrFail($write->auditId)->event,
        );
        $this->assertSame('ran', $this->access()->during($admin, $write, fn () => 'ran'));
    }

    /**
     * `hide_admin_activity` hides what panel Owners do on a customer's server.
     * An Owner who goes through the delegated path is still staff entering that
     * server, and the customer still sees it.
     */
    public function testTheCustomerSeesTheRecordEvenWhenAdminActivityIsHidden(): void
    {
        config()->set('activity.hide_admin_activity', true);

        [$customer, $server] = $this->generateTestAccount();
        $this->access()->open($this->ownerAdmin(), $server, 'Ticket #9');

        $response = $this->actingAs($customer)->getJson($this->link($server, '/activity'))->assertOk();

        $this->assertContains(
            ActivityLog::EVENT_DELEGATED_ACCESS_START,
            array_column(array_column($response->json('data'), 'attributes'), 'event'),
        );
    }

    public function testAServerThatIsGoneRefusesTheGrant(): void
    {
        $admin = $this->staff();
        $server = $this->createServerModel();
        $grant = $this->access()->open($admin, $server, 'why');

        Server::query()->whereKey($server->id)->update(['uuid' => '00000000-0000-4000-8000-00000000dead']);

        $this->expectException(AuthorizationException::class);

        $this->access()->during($admin, $grant, fn () => $this->fail('must not run'));
    }
}
