<?php

namespace Everest\Tests\Unit\Extensions\Sdk;

use Everest\Tests\TestCase;
use Everest\Extensions\Sdk\Services\DelegatedAccess;
use Everest\Extensions\Sdk\Services\InternalDispatch;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
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

    /**
     * The gate reads the runtime plan rather than the manifest on disk, so a
     * package that is disabled, unsigned, on the wrong manifest version, or
     * whose capability projection no longer matches the hash an administrator
     * approved holds nothing — the same conditions that stop its routes loading.
     */
    public function testTheGateIsTheRuntimePlanRatherThanTheManifest(): void
    {
        $this->assertTrue(
            method_exists(ExtensionRuntimePlanService::class, 'grantsPrivilege'),
            'The gate must ask the plan, which is what applies signature and hash state.',
        );
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
