<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Everest\Extensions\Sdk\Services\PanelActivity;
use Everest\Services\Extensions\ExtensionCallerGuard;
use Everest\Exceptions\Service\Extension\ForeignExtensionIdException;

/**
 * A package may use SDK services only in its own name.
 *
 * The fixtures are real files under a package directory, because the guard
 * reads where a call came from, not what it says — so the only honest test is
 * code that actually runs from inside `app/Extensions/Packages/<id>/`.
 */
class ExtensionCallerGuardTest extends TestCase
{
    private static ?string $root = null;

    private ?string $originalBasePath = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Once per process: the fixtures declare functions, and a second
        // require of a different copy would redeclare them.
        self::$root = sys_get_temp_dir() . '/m12labs-caller-guard-' . bin2hex(random_bytes(8));
        $packages = self::$root . '/app/Extensions/Packages';

        File::ensureDirectoryExists($packages . '/alpha');
        File::ensureDirectoryExists($packages . '/beta');

        File::put($packages . '/alpha/Calls.php', <<<'PHP'
<?php

namespace CallerGuardFixture\Alpha;

use Everest\Extensions\Sdk\Services\PanelActivity;
use Everest\Services\Extensions\ExtensionCallerGuard;

function activityAs(string $id): PanelActivity
{
    return PanelActivity::for($id);
}

function launderedThroughCallUserFunc(string $id): PanelActivity
{
    return call_user_func([PanelActivity::class, 'for'], $id);
}

function launderedThroughTheContainer(string $id): PanelActivity
{
    return app()->call([PanelActivity::class, 'for'], ['extensionId' => $id]);
}

function launderedThroughAClosure(string $id): PanelActivity
{
    return (static fn () => PanelActivity::for($id))();
}

function whoIsCalling(): ?string
{
    return ExtensionCallerGuard::callingExtension();
}
PHP);

        File::put($packages . '/beta/Calls.php', <<<'PHP'
<?php

namespace CallerGuardFixture\Beta;

/** Beta reaching into alpha: the innermost package on the stack is alpha. */
function throughAlpha(string $id): \Everest\Extensions\Sdk\Services\PanelActivity
{
    return \CallerGuardFixture\Alpha\activityAs($id);
}
PHP);

        require_once $packages . '/alpha/Calls.php';
        require_once $packages . '/beta/Calls.php';
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$root !== null) {
            File::deleteDirectory(self::$root);
        }

        parent::tearDownAfterClass();
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->originalBasePath = base_path();
        $this->app->setBasePath((string) self::$root);
    }

    public function tearDown(): void
    {
        if ($this->originalBasePath !== null) {
            $this->app->setBasePath($this->originalBasePath);
        }

        parent::tearDown();
    }

    public function testAPackageIsIdentifiedByWhereItsCodeLives(): void
    {
        $this->assertSame('alpha', \CallerGuardFixture\Alpha\whoIsCalling());
    }

    public function testAPackageMayUseTheSdkInItsOwnName(): void
    {
        $this->assertInstanceOf(PanelActivity::class, \CallerGuardFixture\Alpha\activityAs('alpha'));
    }

    public function testAPackageMayNotUseTheSdkInAnotherPackagesName(): void
    {
        $this->expectException(ForeignExtensionIdException::class);
        $this->expectExceptionMessage('Extension "alpha" asked for an SDK service as extension "beta"');

        \CallerGuardFixture\Alpha\activityAs('beta');
    }

    /**
     * Routing the call through core or vendor code leaves the package's own
     * frame on the stack, nearer than any other package's.
     */
    public function testLaunderingTheCallThroughCoreDoesNotChangeTheCaller(): void
    {
        foreach (['launderedThroughCallUserFunc', 'launderedThroughTheContainer', 'launderedThroughAClosure'] as $route) {
            try {
                ('CallerGuardFixture\\Alpha\\' . $route)('beta');
                $this->fail(sprintf('%s: expected a refusal.', $route));
            } catch (ForeignExtensionIdException $exception) {
                $this->assertSame('alpha', $exception->callerId, $route);
            }

            $this->assertInstanceOf(PanelActivity::class, ('CallerGuardFixture\\Alpha\\' . $route)('alpha'), $route);
        }
    }

    /**
     * One package calling into another: the innermost package is the one
     * asking, so beta cannot borrow alpha's name by going through alpha.
     */
    public function testTheInnermostPackageIsTheCaller(): void
    {
        $this->assertInstanceOf(PanelActivity::class, \CallerGuardFixture\Beta\throughAlpha('alpha'));

        $this->expectException(ForeignExtensionIdException::class);

        \CallerGuardFixture\Beta\throughAlpha('beta');
    }

    /** Core's own calls, and tests, run with no package code on the stack. */
    public function testCallsFromOutsideAnyPackageAreCoresOwn(): void
    {
        $this->assertNull(ExtensionCallerGuard::callingExtension());

        ExtensionCallerGuard::assertCallerIs('anything');
        $this->assertInstanceOf(PanelActivity::class, PanelActivity::for('anything'));
    }
}
