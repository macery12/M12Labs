<?php

namespace Everest\Tests\Unit\Extensions\Sdk;

use Everest\Models\User;
use Everest\Models\Server;
use Everest\Tests\TestCase;
use Everest\Services\Access\DelegatedGrant;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Extensions\Sdk\Services\DelegatedAccess;
use Everest\Extensions\Sdk\Services\InternalDispatch;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Access\DelegatedAccess as CoreDelegatedAccess;
use Everest\Services\Access\InternalDispatch as CoreInternalDispatch;
use Everest\Exceptions\Service\Extension\PrivilegeNotGrantedException;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityVocabulary;

/**
 * The gate in front of core's privileged services.
 *
 * Everything else in the SDK is reachable by any package that imports it, which
 * is fine when the worst case is reading a setting the package itself declared.
 * These two are not that: one acts with an administrator's authority on a
 * customer's server, the other dispatches requests as whoever is signed in. So
 * they are reachable only through a manifest declaration an administrator
 * approved on install.
 *
 * Worth being exact about what that buys, because the SDK has never claimed to
 * sandbox anything. Package PHP is trusted, reviewed code running in-process;
 * a package determined to misbehave is not contained by this or by anything
 * else here. What the grant buys is that an operator saw the privilege before
 * installing and can see it afterwards.
 */
class PrivilegedServiceGateTest extends TestCase
{
    /**
     * @param array<int, string> $privileges
     */
    private function planGranting(array $privileges): void
    {
        $plan = \Mockery::mock(ExtensionRuntimePlanService::class);
        $plan->shouldReceive('grantsPrivilege')
            ->andReturnUsing(
                fn (string $id, string $privilege) => $id === 'demo' && in_array($privilege, $privileges, true),
            );

        $this->app->instance(ExtensionRuntimePlanService::class, $plan);
    }

    private function planGrantThenRevoke(string $privilege): void
    {
        $plan = \Mockery::mock(ExtensionRuntimePlanService::class);
        $plan->expects('grantsPrivilege')
            ->twice()
            ->with('demo', $privilege)
            ->andReturn(true, false);

        $this->app->instance(ExtensionRuntimePlanService::class, $plan);
    }

    public function testAPackageThatDeclaredNothingCannotReachEitherService(): void
    {
        $this->planGranting([]);

        foreach ([DelegatedAccess::class, InternalDispatch::class] as $facade) {
            try {
                $facade::for('demo');
                $this->fail($facade . ' must refuse a package that never declared it.');
            } catch (PrivilegeNotGrantedException $e) {
                $this->assertSame('demo', $e->extensionId);
                // The message has to say what to do about it: this is a
                // manifest/code disagreement, and the author is the only person
                // who can fix it.
                $this->assertStringContainsString('capabilities.privileged', $e->getMessage());
            }
        }
    }

    public function testAGrantIsSpecificToTheServiceItNames(): void
    {
        $this->planGranting(['internal_dispatch']);

        $this->assertInstanceOf(InternalDispatch::class, InternalDispatch::for('demo'));

        $this->expectException(PrivilegeNotGrantedException::class);
        DelegatedAccess::for('demo');
    }

    public function testAGrantDoesNotCarryToAnotherPackage(): void
    {
        $this->planGranting(['internal_dispatch']);

        $this->expectException(PrivilegeNotGrantedException::class);
        InternalDispatch::for('someone_else');
    }

    public function testRetainedInternalDispatchRechecksTheLiveGrantBeforeUse(): void
    {
        $this->planGrantThenRevoke(InternalDispatch::PRIVILEGE);
        $core = \Mockery::mock(CoreInternalDispatch::class);
        $core->shouldNotReceive('dispatch');
        $this->app->instance(CoreInternalDispatch::class, $core);

        $dispatch = InternalDispatch::for('demo');

        try {
            $dispatch->get('/api/client/account');
            $this->fail('A retained facade used the core dispatcher after its runtime grant was revoked.');
        } catch (PrivilegeNotGrantedException $exception) {
            $this->assertSame('demo', $exception->extensionId);
            $this->assertSame(InternalDispatch::PRIVILEGE, $exception->privilege);
        }
    }

    #[DataProvider('delegatedSensitiveOperations')]
    public function testRetainedDelegatedAccessRechecksTheLiveGrantBeforeSensitiveUse(string $operation): void
    {
        $this->planGrantThenRevoke(DelegatedAccess::PRIVILEGE);
        $core = \Mockery::mock(CoreDelegatedAccess::class);
        $core->shouldNotReceive($operation);
        $this->app->instance(CoreDelegatedAccess::class, $core);

        $access = DelegatedAccess::for('demo');
        $admin = new User();
        $server = new Server();
        $grant = DelegatedGrant::read('server-uuid', 'Fixture server', 'Support request');
        $actionRan = false;

        try {
            match ($operation) {
                'open' => $access->open($admin, $server, 'Support request'),
                'escalate' => $access->escalate($admin, $server, $grant),
                'during' => $access->during($admin, $grant, function () use (&$actionRan): void {
                    $actionRan = true;
                }),
                'reauthorize' => $access->reauthorize($admin, $grant),
                default => throw new \LogicException(sprintf('Unknown delegated operation [%s].', $operation)),
            };
            $this->fail(sprintf('A retained facade called core %s() after its runtime grant was revoked.', $operation));
        } catch (PrivilegeNotGrantedException $exception) {
            $this->assertSame('demo', $exception->extensionId);
            $this->assertSame(DelegatedAccess::PRIVILEGE, $exception->privilege);
        }

        $this->assertFalse($actionRan);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function delegatedSensitiveOperations(): array
    {
        return [
            'open' => ['open'],
            'escalation' => ['escalate'],
            'delegated action' => ['during'],
            'stored grant resume' => ['reauthorize'],
        ];
    }

    public function testEveryPrivilegeInTheVocabularyHasAFacade(): void
    {
        $this->assertSame(
            ExtensionCapabilityVocabulary::PRIVILEGED,
            [DelegatedAccess::PRIVILEGE, InternalDispatch::PRIVILEGE],
            'A name an administrator can approve with nothing behind it is a dead grant.',
        );
    }
}
