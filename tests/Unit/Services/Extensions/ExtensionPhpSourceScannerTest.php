<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Everest\Exceptions\DisplayException;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Services\Extensions\ExtensionPhpSourceScanner;

/**
 * The PHP counterpart to the frontend import scanner, which the platform went
 * without while frontend code — running in a browser — was strictly gated and
 * backend code — running in the panel process, with the database connection —
 * was not.
 *
 * Two things are being guarded, and they fail differently. A blocking finding
 * refuses the install outright, so a false positive is an operator who cannot
 * install a package with no override available; those rules have to be exact.
 * An advisory finding is a note for a reviewer, so a false positive there costs
 * a line in a log.
 */
class ExtensionPhpSourceScannerTest extends TestCase
{
    private const ID = 'demo';

    private const CONTROLLER = 'app/Extensions/Packages/demo/Http/Controllers/DemoController.php';

    private const SERVICE = 'app/Extensions/Packages/demo/Services/DemoService.php';

    private const ROUTES = 'app/Extensions/Packages/demo/routes/client.php';

    private const MIGRATION = 'app/Extensions/Packages/demo/database/migrations/2026_01_01_000000_create.php';

    private ExtensionPhpSourceScanner $scanner;

    private string $root;

    public function setUp(): void
    {
        parent::setUp();

        $this->scanner = new ExtensionPhpSourceScanner();
        $this->root = sys_get_temp_dir() . '/php-scan-' . uniqid();
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * @param array<string, string> $files path => contents
     *
     * @return array<int, array{path: string, sourcePath: string}>
     */
    private function plans(array $files): array
    {
        $plans = [];
        foreach ($files as $path => $contents) {
            $source = $this->root . '/' . $path;
            File::ensureDirectoryExists(dirname($source));
            File::put($source, $contents);
            $plans[] = ['path' => $path, 'sourcePath' => $source];
        }

        return $plans;
    }

    /** @param array<string, string> $files */
    private function scan(array $files): array
    {
        return $this->scanner->assertSafe(self::ID, $this->plans($files));
    }

    /** @param array<string, string> $files */
    private function assertBlocked(array $files, string $expectedFragment): void
    {
        try {
            $this->scan($files);
            $this->fail('Expected the scanner to refuse: ' . $expectedFragment);
        } catch (DisplayException $exception) {
            $this->assertStringContainsString($expectedFragment, $exception->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The import boundary
    |--------------------------------------------------------------------------
    */

    public function testSdkAndOwnPackageImportsAreAllowed(): void
    {
        $this->scan([
            self::SERVICE => <<<'PHP'
                <?php
                namespace Everest\Extensions\Packages\demo\Services;

                use Illuminate\Support\Str;
                use Everest\Extensions\Sdk\Permission;
                use Everest\Extensions\Sdk\Services\ServerFiles;
                use Everest\Extensions\Packages\demo\Models\Thing;

                class DemoService {}
                PHP,
        ]);

        $this->addToAssertionCount(1);
    }

    public function testPanelInternalsAreRefused(): void
    {
        $this->assertBlocked([
            self::SERVICE => <<<'PHP'
                <?php
                namespace Everest\Extensions\Packages\demo\Services;

                use Everest\Services\Servers\ServerDeletionService;

                class DemoService {}
                PHP,
        ], 'imports Everest\Services\Servers\ServerDeletionService');
    }

    /**
     * The models a package receives from route binding, or relates its own
     * tables to, stay reachable by their core names. A DTO cannot be a
     * `belongsTo()` target, and core binds `{server}` before a package's route
     * file is required.
     */
    public function testRouteBoundAndRelationTargetModelsStayReachable(): void
    {
        $this->scan([
            self::CONTROLLER => <<<'PHP'
                <?php
                namespace Everest\Extensions\Packages\demo\Http\Controllers;

                use Everest\Models\Server;
                use Everest\Models\Allocation;
                use Everest\Extensions\Sdk\Http\ClientApiController;
                use Everest\Extensions\Packages\demo\Http\Requests\ShowRequest;

                class DemoController extends ClientApiController
                {
                    public function show(ShowRequest $request, Server $server) {}
                }
                PHP,
        ]);

        $this->addToAssertionCount(1);
    }

    /** A near-miss namespace must not be admitted for a different extension. */
    public function testAnotherPackagesNamespaceIsRefused(): void
    {
        $this->assertBlocked([
            self::SERVICE => <<<'PHP'
                <?php
                namespace Everest\Extensions\Packages\demo\Services;

                use Everest\Extensions\Packages\demo_other\Services\Secrets;

                class DemoService {}
                PHP,
        ], 'Everest\Extensions\Packages\demo_other\Services\Secrets');
    }

    /**
     * The hole the import rule leaves on its own.
     *
     * A `use` statement is the polite way to reach a class, not the only way.
     * This was found by a real package: node_health_history's schedule.php
     * reached ExtensionConfig inline, imported nothing, and sailed past an
     * import-only scan.
     */
    public function testFullyQualifiedReferencesCannotBypassTheImportRule(): void
    {
        $this->assertBlocked([
            'app/Extensions/Packages/demo/schedule.php' => <<<'PHP'
                <?php
                return function ($schedule) {
                    $config = \Everest\Models\ExtensionConfig::getByExtensionId('demo');
                };
                PHP,
        ], 'refers to Everest\Models\ExtensionConfig');
    }

    /** …and the same form is fine when it names something allowed. */
    public function testAllowedSymbolsMayBeReferencedInline(): void
    {
        $this->scan([
            self::SERVICE => <<<'PHP'
                <?php
                namespace Everest\Extensions\Packages\demo\Services;

                class DemoService
                {
                    public function relation(): string
                    {
                        return \Everest\Models\Server::class;
                    }
                }
                PHP,
        ]);

        $this->addToAssertionCount(1);
    }

    /** A class name inside a string or docblock is not a reference. */
    public function testQuotedClassNamesAreNotReferences(): void
    {
        $this->scan([
            self::SERVICE => <<<'PHP'
                <?php
                namespace Everest\Extensions\Packages\demo\Services;

                /** Never reach \Everest\Services\Servers\ServerDeletionService from here. */
                class DemoService
                {
                    public const NOTE = '\Everest\Models\Setting is off limits';
                }
                PHP,
        ]);

        $this->addToAssertionCount(1);
    }

    public function testNonEverestImportsAreNotTheScannersBusiness(): void
    {
        $this->scan([
            self::SERVICE => <<<'PHP'
                <?php
                namespace Everest\Extensions\Packages\demo\Services;

                use GuzzleHttp\Client;
                use Symfony\Component\Yaml\Yaml;
                use Illuminate\Support\Facades\Cache;

                class DemoService {}
                PHP,
        ]);

        $this->addToAssertionCount(1);
    }

    /*
    |--------------------------------------------------------------------------
    | Execution and route integrity
    |--------------------------------------------------------------------------
    */

    public static function dangerousCallProvider(): array
    {
        return [
            'eval' => ['eval($payload);', 'eval()'],
            'shell_exec' => ['shell_exec($cmd);', 'shell_exec()'],
            'proc_open' => ['proc_open($cmd, [], $pipes);', 'proc_open()'],
            'passthru' => ['passthru($cmd);', 'passthru()'],
        ];
    }

    #[DataProvider('dangerousCallProvider')]
    public function testCodeAndShellExecutionIsRefused(string $statement, string $expected): void
    {
        $this->assertBlocked([
            self::SERVICE => "<?php\nnamespace Everest\\Extensions\\Packages\\demo\\Services;\nclass DemoService { public function run() { {$statement} } }",
        ], $expected);
    }

    public function testWithoutMiddlewareIsRefused(): void
    {
        $this->assertBlocked([
            self::ROUTES => "<?php\nRoute::get('/x', [Demo::class, 'index'])->withoutMiddleware('auth');",
        ], 'withoutMiddleware()');
    }

    public function testRouteClosuresAreRefused(): void
    {
        $this->assertBlocked([
            self::ROUTES => "<?php\nRoute::get('/x', function () { return 'hi'; });",
        ], 'closure');
    }

    public function testControllerBackedRoutesAreFine(): void
    {
        $this->scan([
            self::ROUTES => "<?php\nRoute::get('/x', [DemoController::class, 'index']);\nRoute::post('/y', [DemoController::class, 'store']);",
        ]);

        $this->addToAssertionCount(1);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization surface
    |--------------------------------------------------------------------------
    */

    public function testAPublicActionWithoutAFormRequestIsRefused(): void
    {
        $this->assertBlocked([
            self::CONTROLLER => <<<'PHP'
                <?php
                namespace Everest\Extensions\Packages\demo\Http\Controllers;

                use Everest\Models\Server;
                use Everest\Extensions\Sdk\Http\ClientApiController;

                class DemoController extends ClientApiController
                {
                    public function destroy(Server $server) {}
                }
                PHP,
        ], 'destroy()');
    }

    /** Helpers are allowed to exist; they just must not be public. */
    public function testNonPublicHelpersAreNotActions(): void
    {
        $this->scan([
            self::CONTROLLER => <<<'PHP'
                <?php
                namespace Everest\Extensions\Packages\demo\Http\Controllers;

                use Everest\Models\Server;
                use Everest\Extensions\Sdk\Http\ClientApiController;
                use Everest\Extensions\Packages\demo\Http\Requests\ShowRequest;

                class DemoController extends ClientApiController
                {
                    public function __construct() { parent::__construct(); }

                    public function show(ShowRequest $request, Server $server) {}

                    private function helper(Server $server): array { return []; }

                    protected function other(int $id): void {}
                }
                PHP,
        ]);

        $this->addToAssertionCount(1);
    }

    public function testAnAdminFormRequestWithoutPermissionIsRefused(): void
    {
        $this->assertBlocked([
            'app/Extensions/Packages/demo/Http/Requests/AdminThingRequest.php' => <<<'PHP'
                <?php
                namespace Everest\Extensions\Packages\demo\Http\Requests;

                use Everest\Extensions\Sdk\Http\ApplicationApiRequest;

                class AdminThingRequest extends ApplicationApiRequest
                {
                    public function rules(): array { return []; }
                }
                PHP,
        ], 'no permission()');
    }

    /*
    |--------------------------------------------------------------------------
    | Database ownership (P5.2)
    |--------------------------------------------------------------------------
    */

    public static function schemaVerbProvider(): array
    {
        return [
            'create' => ["Schema::create('users', function (\$t) {});"],
            'table' => ["Schema::table('users', function (\$t) {});"],
            'drop' => ["Schema::drop('users');"],
            'dropIfExists' => ["Schema::dropIfExists('users');"],
            'rename' => ["Schema::rename('users', 'users_old');"],
        ];
    }

    /**
     * The old rule read `Schema::create` and nothing else, so altering or
     * dropping a core table was not evasion — it was an operation the rule
     * never looked at.
     */
    #[DataProvider('schemaVerbProvider')]
    public function testSchemaVerbsAgainstCoreTablesAreRefused(string $statement): void
    {
        $this->assertBlocked([
            self::MIGRATION => "<?php\nreturn new class { public function up() { {$statement} } };",
        ], '"users"');
    }

    public function testTheExtensionsOwnTablesAreFine(): void
    {
        $this->scan([
            self::MIGRATION => <<<'PHP'
                <?php
                return new class {
                    public function up() {
                        Schema::create('ext_demo_things', function ($t) {});
                        Schema::table('ext_demo_things', function ($t) {});
                    }
                    public function down() { Schema::dropIfExists('ext_demo_things'); }
                };
                PHP,
        ]);

        $this->addToAssertionCount(1);
    }

    /*
    |--------------------------------------------------------------------------
    | Advisory findings
    |--------------------------------------------------------------------------
    */

    /**
     * An outbound HTTP call is the entire point of an integration package, so
     * this is a note for a reviewer and must never refuse an install.
     */
    public function testOutboundHttpIsAdvisoryRatherThanBlocking(): void
    {
        $advisory = $this->scan([
            self::SERVICE => <<<'PHP'
                <?php
                namespace Everest\Extensions\Packages\demo\Services;
                class DemoService {
                    public function push() { return file_get_contents('https://api.example.com/v1/x'); }
                }
                PHP,
        ]);

        $this->assertNotEmpty($advisory);
        $this->assertStringContainsString('api.example.com', implode("\n", $advisory));
    }

    public function testInterpolatedRawSqlIsAdvisory(): void
    {
        $advisory = $this->scan([
            self::SERVICE => <<<'PHP'
                <?php
                namespace Everest\Extensions\Packages\demo\Services;
                class DemoService {
                    public function find($id) { return DB::statement("select * from ext_demo_things where id = $id"); }
                }
                PHP,
        ]);

        $this->assertStringContainsString('bindings', implode("\n", $advisory));
    }

    /*
    |--------------------------------------------------------------------------
    | False positives
    |--------------------------------------------------------------------------
    */

    /**
     * The expensive failure mode. A blocking scanner that fires on prose has
     * no override, so a docblock explaining that a package deliberately avoids
     * exec() must not be the reason it cannot be installed.
     */
    public function testCommentsAndStringsDoNotTriggerBlockingRules(): void
    {
        $this->scan([
            self::SERVICE => <<<'PHP'
                <?php
                namespace Everest\Extensions\Packages\demo\Services;

                /**
                 * This service deliberately never calls eval() or shell_exec(),
                 * and does not use withoutMiddleware() on its routes.
                 */
                class DemoService
                {
                    // Nothing here calls system() either.
                    public const NOTE = 'we never call eval() here';

                    public function describe(): string
                    {
                        return 'Schema::create("users") would be wrong';
                    }
                }
                PHP,
        ]);

        $this->addToAssertionCount(1);
    }

    /** Files outside the package's backend are another scanner's problem. */
    public function testOnlyTheExtensionsOwnPhpIsScanned(): void
    {
        $this->scan([
            'frontend/src/extensions/packages/demo/pages/admin/thing.tsx' => "import x from '@/lib/http';",
            'app/Extensions/Packages/other/Services/Bad.php' => '<?php eval($x);',
        ]);

        $this->addToAssertionCount(1);
    }

    /** Every violation at once, so fixing them is not an install-attempt loop. */
    public function testAllViolationsAreReportedTogether(): void
    {
        try {
            $this->scan([
                self::SERVICE => <<<'PHP'
                    <?php
                    namespace Everest\Extensions\Packages\demo\Services;

                    use Everest\Services\Servers\ServerDeletionService;

                    class DemoService { public function run() { eval($x); } }
                    PHP,
            ]);
            $this->fail('Expected a refusal.');
        } catch (DisplayException $exception) {
            $this->assertStringContainsString('ServerDeletionService', $exception->getMessage());
            $this->assertStringContainsString('eval()', $exception->getMessage());
        }
    }
}
