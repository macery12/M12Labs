<?php

namespace Everest\Tests\Unit\Services\Access;

use Everest\Models\User;
use Everest\Models\Server;
use Everest\Tests\TestCase;
use Everest\Facades\Activity;
use Everest\Models\AdminRole;
use Everest\Models\Permission;
use Everest\Services\Access\DelegatedGrant;
use Everest\Services\Access\DelegatedAccess;
use Everest\Services\Access\DelegatedSession;
use Illuminate\Auth\Access\AuthorizationException;
use Everest\Services\Authorization\AdminAuthorizer;

/**
 * Delegated, audited administrator access to a customer's server.
 *
 * This is the one place in the panel where somebody who is neither the server's
 * owner nor a panel Owner gets through `AuthenticateServerAccess` and
 * `ServerPolicy`, so the tests here are mostly about how narrow that gap is:
 * that it is shut unless a session is open, that it only ever admits the
 * abilities the session was granted, that nothing outside core can name those
 * abilities, and that none of it can be widened by editing stored JSON.
 *
 * The feature was born inside the AI module and the AI module is still its only
 * caller, but nothing here knows that — which is the point. An extension that
 * could widen what `ServerPolicy` allows would be a privilege-escalation
 * surface; this is the shape that avoids needing one.
 */
class DelegatedAccessTest extends TestCase
{
    private function server(): Server
    {
        $server = new Server();
        $server->id = 14;
        $server->uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $server->name = 'Survival SMP';
        $server->owner_id = 27;

        return $server;
    }

    private function binding(bool $writable = false): DelegatedGrant
    {
        $base = DelegatedGrant::read(
            serverUuid: $this->server()->uuid,
            serverName: 'Survival SMP',
            reason: 'Ticket #2 — server will not start',
            ticketId: 2,
        );

        return $writable ? $base->escalated() : $base;
    }

    private function access(bool $permitted = true): DelegatedAccess
    {
        $authorizer = \Mockery::mock(AdminAuthorizer::class);
        $authorizer->shouldReceive('hasCapability')
            ->andReturnUsing(
                fn (User $user, string $capability) => $permitted && $capability === AdminRole::SERVERS_ASSIST,
            );

        return new DelegatedAccess($authorizer, new DelegatedSession());
    }

    /*
    |--------------------------------------------------------------------------
    | The ambient window
    |--------------------------------------------------------------------------
    */

    public function testTheSessionIsShutOutsideTheCallItWraps(): void
    {
        $session = new DelegatedSession();
        $admin = User::factory()->make(['id' => 7]);

        // Nothing outside `during()` can open one, and this is the state every
        // browser request sees.
        $this->assertFalse($session->covers($admin, $this->server()));
        $this->assertFalse($session->permits($admin, $this->server(), Permission::ACTION_FILE_READ));
    }

    public function testTheSessionIsOpenOnlyForTheDurationOfTheCall(): void
    {
        $session = new DelegatedSession();
        $admin = User::factory()->make(['id' => 7]);
        $server = $this->server();

        $inside = $session->during($admin, $this->binding(), fn () => $session->covers($admin, $server));

        $this->assertTrue($inside);
        $this->assertFalse($session->covers($admin, $server));
    }

    public function testTheSessionClosesEvenWhenTheCallThrows(): void
    {
        $session = new DelegatedSession();
        $admin = User::factory()->make(['id' => 7]);

        try {
            $session->during($admin, $this->binding(), function () {
                throw new \RuntimeException('the node was unreachable');
            });
        } catch (\RuntimeException) {
            // A call that blows up must not leave an administrator standing
            // inside a customer's server for the rest of the request.
        }

        $this->assertFalse($session->covers($admin, $this->server()));
    }

    public function testASessionDoesNotCoverAnotherServer(): void
    {
        $session = new DelegatedSession();
        $admin = User::factory()->make(['id' => 7]);

        $other = new Server();
        $other->uuid = '11111111-2222-3333-4444-555555555555';

        $seen = $session->during($admin, $this->binding(), fn () => $session->covers($admin, $other));

        $this->assertFalse($seen);
    }

    public function testASessionDoesNotCoverAnotherAdministrator(): void
    {
        $session = new DelegatedSession();
        $admin = User::factory()->make(['id' => 7]);
        $colleague = User::factory()->make(['id' => 8]);

        $seen = $session->during($admin, $this->binding(), fn () => $session->covers($colleague, $this->server()));

        $this->assertFalse($seen);
    }

    public function testARestrictedSessionRefusesAbilitiesItWasNotGranted(): void
    {
        $session = new DelegatedSession();
        $admin = User::factory()->make(['id' => 7]);
        $server = $this->server();

        [$read, $write, $delete] = $session->during($admin, $this->binding(), fn () => [
            $session->permits($admin, $server, Permission::ACTION_FILE_READ),
            $session->permits($admin, $server, Permission::ACTION_FILE_UPDATE),
            $session->permits($admin, $server, Permission::ACTION_FILE_DELETE),
        ]);

        $this->assertTrue($read);
        // Read-only until an administrator approves the widening separately.
        $this->assertFalse($write);
        // Never, at any tier: fixing a server does not require destroying part
        // of it, and the customer owns the files.
        $this->assertFalse($delete);
    }

    public function testEscalationGrantsWritesButStillNotDeletion(): void
    {
        $session = new DelegatedSession();
        $admin = User::factory()->make(['id' => 7]);
        $server = $this->server();

        [$write, $restart, $delete] = $session->during($admin, $this->binding(writable: true), fn () => [
            $session->permits($admin, $server, Permission::ACTION_FILE_UPDATE),
            $session->permits($admin, $server, Permission::ACTION_CONTROL_RESTART),
            $session->permits($admin, $server, Permission::ACTION_FILE_DELETE),
        ]);

        $this->assertTrue($write);
        $this->assertTrue($restart);
        $this->assertFalse($delete);
    }

    public function testSessionsCannotNest(): void
    {
        $session = new DelegatedSession();
        $admin = User::factory()->make(['id' => 7]);

        $this->expectException(\LogicException::class);

        $session->during($admin, $this->binding(), function () use ($session, $admin) {
            // A nested open would restore the *wider* grant on its way out.
            $session->during($admin, $this->binding(writable: true), fn () => null);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | The door
    |--------------------------------------------------------------------------
    */

    public function testOpeningRequiresTheCapabilityAndWritesTheCustomerVisibleRecord(): void
    {
        $admin = User::factory()->make(['id' => 7, 'username' => 'staff']);
        $server = $this->server();

        Activity::shouldReceive('event')->once()->with('server:access.delegated.start')->andReturnSelf();
        Activity::shouldReceive('actor')->once()->with($admin)->andReturnSelf();
        Activity::shouldReceive('subject')->once()->with($server)->andReturnSelf();
        Activity::shouldReceive('property')->once()->andReturnSelf();
        Activity::shouldReceive('log')->once()->andReturnNull();

        $grant = $this->access()->open($admin, $server, 'Ticket #2 — server will not start', 2);

        $this->assertSame($server->uuid, $grant->serverUuid);
        $this->assertFalse($grant->writable);
        $this->assertSame(DelegatedGrant::READ_ABILITIES, $grant->abilities);
    }

    public function testOpeningIsRefusedWithoutTheCapability(): void
    {
        // The audit row is the tell: nothing is written, because nothing opened.
        Activity::shouldReceive('event')->never();

        $this->expectException(AuthorizationException::class);

        $this->access(permitted: false)->open(User::factory()->make(['id' => 7]), $this->server(), 'why');
    }

    /**
     * Widening takes the grant already in force, so there is no way to spell
     * "escalate onto something else" — the target comes from what was approved,
     * not from an argument a caller supplies alongside it.
     */
    public function testEscalationCannotBeRetargetedAtAnotherServer(): void
    {
        $other = new Server();
        $other->uuid = '11111111-2222-3333-4444-555555555555';
        $other->name = 'Someone else';

        Activity::shouldReceive('event')->never();

        $this->expectException(AuthorizationException::class);

        $this->access()->escalate(User::factory()->make(['id' => 7]), $other, $this->binding());
    }

    /**
     * Time passes between approving a session and dispatching inside it, so the
     * capability is asked for again rather than trusted from when the window was
     * requested.
     */
    public function testTheWindowRechecksTheCapability(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->access(permitted: false)->during(
            User::factory()->make(['id' => 7]),
            $this->binding(),
            fn () => $this->fail('The call should not have run.'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | What a caller may ask for
    |--------------------------------------------------------------------------
    */

    /**
     * The decision the whole split rests on: a caller outside core says which
     * server and why, and never what for. There is no constructor to pass an
     * ability list to, so an extension consuming this cannot invent authority
     * core did not design — which is what makes it safe for core's policy to
     * consult a window an extension asked to open.
     */
    public function testAGrantCannotBeSpelledWithAnArbitraryAbilityList(): void
    {
        $this->assertTrue(
            (new \ReflectionMethod(DelegatedGrant::class, '__construct'))->isPrivate(),
            'Naming abilities from outside core would make this an authorization-contributor surface.',
        );

        $named = array_filter(
            (new \ReflectionClass(DelegatedGrant::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m) => $m->isStatic() && $m->getReturnType()?->__toString() === 'self',
        );

        $this->assertSame(
            ['read'],
            array_values(array_map(fn (\ReflectionMethod $m) => $m->getName(), $named)),
            'Only a read-only grant may be minted by name; anything wider is an escalation of one.',
        );
    }

    public function testStoredAbilitiesOutsideTheDeclaredListsAreDiscarded(): void
    {
        $tampered = DelegatedGrant::fromArray([
            'server_uuid' => $this->server()->uuid,
            'server_name' => 'Survival SMP',
            'reason' => 'x',
            'abilities' => [Permission::ACTION_FILE_READ, Permission::ACTION_FILE_DELETE, 'settings.reinstall'],
            'writable' => true,
        ]);

        // A grant is authority, not preferences. A non-canonical ability set is
        // rejected whole rather than repaired into something the user did not
        // approve.
        $this->assertNull($tampered);
    }

    /**
     * The tool names a session advertises used to be stored beside its
     * authority. They are derived now, and nothing reads a stored copy back — so
     * a blob still carrying them is refused rather than partly believed.
     */
    public function testAStoredGrantCarryingAnythingElseIsRejected(): void
    {
        $stored = $this->binding()->toArray();
        $stored['tools'] = ['files_read', 'files_write'];

        $this->assertNull(DelegatedGrant::fromArray($stored));
    }

    public function testAGrantWithoutAServerUuidIsRejectedOutright(): void
    {
        $this->assertNull(DelegatedGrant::fromArray(['reason' => 'x', 'abilities' => ['file.read']]));
        $this->assertNull(DelegatedGrant::fromArray('not an array'));
    }
}
