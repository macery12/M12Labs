<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionPackage;
use Illuminate\Support\Facades\File;
use Everest\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\ExtensionPackageIntegrityService;

/**
 * Runtime integrity reuses a file's digest while its stat fingerprint holds.
 *
 * Hashing every enabled package's files is most of what a runtime-plan read
 * costs, and the plan is read many times a request. What these tests pin down
 * is that the shortcut never vouches for bytes it did not hash: any write
 * changes the fingerprint, and a file changed too recently to trust is hashed
 * every time.
 */
class ExtensionIntegrityDigestCacheTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    private const PATH = 'app/Extensions/Packages/digest_fixture/Support/Marker.php';

    private const ORIGINAL = "<?php\n// authentic\n";

    /** The same length as ORIGINAL, so size alone cannot tell them apart. */
    private const SAME_SIZE = "<?php\n// tampered!\n";

    public function setUp(): void
    {
        parent::setUp();

        $this->forgetDigests();
    }

    public function tearDown(): void
    {
        $this->forgetDigests();

        parent::tearDown();
    }

    public function testAnUnchangedFileIsHashedOnceItIsOldEnoughToTrust(): void
    {
        $package = $this->package();
        $service = $this->service(clockAhead: 10);

        $this->assertTrue($service->inspect($package)->valid);
        $this->assertTrue($service->inspect($package)->valid);

        $this->assertSame(1, $service->hashed);
    }

    /**
     * A file written in the last couple of seconds shares its fingerprint with
     * any other same-size write in that second, so its digest is not kept.
     */
    public function testAFreshlyWrittenFileIsHashedEveryTime(): void
    {
        $package = $this->package();
        $service = $this->service(clockAhead: 0);

        $service->inspect($package);
        $service->inspect($package);

        $this->assertSame(2, $service->hashed);
    }

    /** Swapping a same-size file in by rename, the usual way to replace one. */
    public function testASameSizeReplacementIsCaughtAfterTheDigestWasCached(): void
    {
        $package = $this->package();
        $service = $this->service(clockAhead: 10);
        $this->assertTrue($service->inspect($package)->valid);

        $replacement = base_path(self::PATH) . '.new';
        File::put($replacement, self::SAME_SIZE);
        rename($replacement, base_path(self::PATH));

        $result = $service->inspect($package);

        $this->assertFalse($result->valid);
        $this->assertSame([self::PATH], $result->modifiedFiles);
    }

    /**
     * The case the fingerprint's ctime exists for: same inode, same size, only
     * the bytes differ. Run on the real clock, so the digest is cached exactly
     * as production would cache it — which takes waiting out the racy window.
     */
    public function testASameSizeOverwriteInPlaceIsCaughtOnTheRealClock(): void
    {
        $package = $this->package();
        $service = $this->service(clockAhead: 0);

        sleep(3);
        $this->assertTrue($service->inspect($package)->valid);
        $this->assertTrue($service->inspect($package)->valid);
        $this->assertSame(1, $service->hashed, 'The digest should have been cached.');

        $inode = fileinode(base_path(self::PATH));
        file_put_contents(base_path(self::PATH), self::SAME_SIZE);
        clearstatcache(true, base_path(self::PATH));
        $this->assertSame($inode, fileinode(base_path(self::PATH)));

        $this->assertFalse($service->inspect($package)->valid);
    }

    public function testAnEditInPlaceIsCaughtAfterTheDigestWasCached(): void
    {
        $package = $this->package();
        $service = $this->service(clockAhead: 10);
        $this->assertTrue($service->inspect($package)->valid);

        File::append(base_path(self::PATH), "// appended\n");

        $this->assertFalse($service->inspect($package)->valid);
    }

    private function package(): ExtensionPackage
    {
        return ExtensionPackage::query()->create(array_merge(
            $this->signedRuntimePackageAttributes('digest_fixture', new ExtensionCapabilitySet(), [self::PATH => self::ORIGINAL]),
            ['state' => 'enabled'],
        ));
    }

    /**
     * The real service with a movable clock and a count of how many files it
     * actually read.
     */
    private function service(int $clockAhead): ExtensionPackageIntegrityService
    {
        $base = app(ExtensionPackageIntegrityService::class);
        $dependencies = array_map(
            fn (\ReflectionParameter $parameter) => app((string) $parameter->getType()),
            (new \ReflectionMethod($base, '__construct'))->getParameters(),
        );

        return new class ($clockAhead, ...$dependencies) extends ExtensionPackageIntegrityService {
            public int $hashed = 0;

            public function __construct(private int $clockAhead, mixed ...$dependencies)
            {
                parent::__construct(...$dependencies);
            }

            protected function hashFile(string $path): ?string
            {
                ++$this->hashed;

                return parent::hashFile($path);
            }

            protected function now(): int
            {
                return time() + $this->clockAhead;
            }
        };
    }

    private function forgetDigests(): void
    {
        (new \ReflectionProperty(ExtensionPackageIntegrityService::class, 'digests'))->setValue(null, []);
    }
}
