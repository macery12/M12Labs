<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Mockery\MockInterface;
use Illuminate\Support\Facades\DB;
use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Illuminate\Support\Facades\Schema;
use Everest\Models\ExtensionHookHealth;
use Illuminate\Database\Schema\Blueprint;
use Everest\Models\ExtensionHookTombstone;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Servers\ServerDeletionService;
use Everest\Repositories\Wings\DaemonServerRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Extensions\ExtensionHookDispatcher;
use Everest\Services\Databases\DatabaseManagementService;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\Definitions\HookDefinition;
use Everest\Extensions\Packages\fixture_hooks\Hooks\RecordingHandler;

/**
 * Hook delivery, and specifically the ordering that makes server.pre_delete
 * useful at all.
 *
 * An extension's tables reference servers with cascadeOnDelete, so the rows a
 * cleanup handler needs are removed at the exact instant the server row is. The
 * hook therefore has to fire before the deletion transaction opens, and the
 * fixture asserts that by counting its own rows from inside the handler rather
 * than by inspecting call order from outside.
 */
class ExtensionHookDeliveryTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    private MockInterface $daemonServerRepository;

    private MockInterface $databaseManagementService;

    public function setUp(): void
    {
        parent::setUp();

        RecordingHandler::reset();
        ExtensionRuntimePlanService::flush();
        config()->set('modules.extensions.enabled', true);
        config()->set('logging.default', 'null');

        $this->daemonServerRepository = \Mockery::mock(DaemonServerRepository::class);
        $this->databaseManagementService = \Mockery::mock(DatabaseManagementService::class);
        $this->app->instance(DaemonServerRepository::class, $this->daemonServerRepository);
        $this->app->instance(DatabaseManagementService::class, $this->databaseManagementService);

        // The shape a real extension table has: a cascadeOnDelete FK to
        // servers. Without this the pre-delete ordering would be untestable —
        // and the reason for the ordering would not exist.
        Schema::create('ext_fixture_hooks_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('server_id');
            $table->string('record');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function tearDown(): void
    {
        Schema::dropIfExists('ext_fixture_hooks_rows');
        RecordingHandler::reset();
        ExtensionRuntimePlanService::flush();

        parent::tearDown();
    }

    private function installFixture(string $mode = 'synchronous_best_effort'): void
    {
        $capabilities = new ExtensionCapabilitySet(
            hooks: [new HookDefinition(
                event: 'server.pre_delete',
                handler: 'RecordingHandler',
                mode: $mode,
            )],
        );

        ExtensionPackage::create(array_merge($this->signedRuntimePackageAttributes(
            'fixture_hooks',
            $capabilities,
            ['app/Extensions/Packages/fixture_hooks/Hooks/RecordingHandler.php' => "<?php\n"],
        ), [
            'name' => 'Hook fixture',
            'state' => 'enabled',
        ]));

        ExtensionConfig::create(['extension_id' => 'fixture_hooks', 'enabled' => true]);
        ExtensionRuntimePlanService::flush();
    }

    private function deletableServer(): \Everest\Models\Server
    {
        $server = $this->createServerModel();

        $this->daemonServerRepository->expects('setServer')->andReturnSelf();
        $this->daemonServerRepository->expects('delete')->andReturnNull();

        DB::table('ext_fixture_hooks_rows')->insert([
            ['server_id' => $server->id, 'record' => 'dns-a'],
            ['server_id' => $server->id, 'record' => 'dns-b'],
        ]);

        return $server;
    }

    /**
     * The property the whole design turns on: when the handler runs, the rows
     * it owns are still there. Asserted from inside the handler, because after
     * the fact there is nothing left to look at.
     */
    public function testASynchronousPreDeleteHandlerStillSeesItsOwnRows(): void
    {
        $this->installFixture();
        $server = $this->deletableServer();

        app(ServerDeletionService::class)->handle($server);

        $this->assertCount(1, RecordingHandler::$calls);
        $this->assertSame('server.pre_delete', RecordingHandler::$calls[0]['event']);
        $this->assertSame(2, RecordingHandler::$calls[0]['ownRowsVisible']);

        // And the cascade did its job afterwards.
        $this->assertSame(0, DB::table('ext_fixture_hooks_rows')->where('server_id', $server->id)->count());
        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
    }

    /**
     * The payload is a snapshot of scalars. A model would be lazily loaded at
     * the handler's whim and, for a queued handler, re-read from a database
     * that has moved on.
     */
    public function testThePayloadIsMaterializedScalars(): void
    {
        $this->installFixture();
        $server = $this->deletableServer();

        app(ServerDeletionService::class)->handle($server);

        $payload = RecordingHandler::$calls[0]['payload'];

        $this->assertSame($server->id, $payload['serverId']);
        $this->assertSame($server->uuid, $payload['serverUuid']);
        $this->assertIsArray($payload['allocations']);
        $this->assertNotEmpty($payload['allocations']);

        foreach ($payload as $value) {
            $this->assertTrue(
                is_scalar($value) || is_array($value) || $value === null,
                'A hook payload must carry no objects.'
            );
        }
    }

    /**
     * Best effort means core proceeds. A handler that throws must not be able
     * to keep a server undeletable.
     */
    public function testAThrowingHandlerNeverBlocksTheDeletion(): void
    {
        $this->installFixture();
        RecordingHandler::$shouldThrow = true;
        $server = $this->deletableServer();

        app(ServerDeletionService::class)->handle($server);

        $this->assertDatabaseMissing('servers', ['id' => $server->id]);

        $health = ExtensionHookHealth::query()->where('extension_id', 'fixture_hooks')->firstOrFail();
        $this->assertSame(1, $health->failures);
        $this->assertSame(1, $health->consecutive_failures);
        $this->assertStringContainsString('Deliberate handler failure', (string) $health->last_error);
    }

    /**
     * A tombstone is written for every declared handler before any of them
     * runs, whatever the mode: a queued handler executes after the cascade and
     * has nothing else left to read.
     */
    public function testATombstoneIsWrittenForEveryDeclaredHandler(): void
    {
        $this->installFixture('queued_at_least_once');
        $server = $this->deletableServer();

        app(ServerDeletionService::class)->handle($server);

        $tombstone = ExtensionHookTombstone::query()
            ->where('extension_id', 'fixture_hooks')
            ->where('event', 'server.pre_delete')
            ->firstOrFail();

        $this->assertSame('RecordingHandler', $tombstone->handler);
        $this->assertSame($server->id, $tombstone->envelope['payload']['serverId']);
        // Written before the deletion, so it outlives the rows the cascade took.
        $this->assertSame(0, DB::table('ext_fixture_hooks_rows')->where('server_id', $server->id)->count());
    }

    /** With nothing installed, dispatch is a no-op and deletion is unchanged. */
    public function testDispatchIsANoOpWithNoExtensionsInstalled(): void
    {
        $server = $this->deletableServer();

        app(ServerDeletionService::class)->handle($server);

        $this->assertSame([], RecordingHandler::$calls);
        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
    }

    /**
     * Resolution is closed: a handler that is declared but does not exist, or
     * does not implement the interface, is skipped rather than guessed at.
     */
    public function testAnUndeclaredHandlerIsNeverResolved(): void
    {
        $dispatcher = app(ExtensionHookDispatcher::class);

        $this->assertNull($dispatcher->resolve('fixture_hooks', new HookDefinition(
            event: 'server.pre_delete',
            handler: 'NoSuchHandler',
            mode: 'synchronous_best_effort',
        )));
    }

    /**
     * A handler that keeps failing takes itself out of delivery, and takes only
     * itself: the breaker is keyed on (extension, event, handler), never on the
     * extension, so a broken webhook does not cost a package its routes, pages
     * or other subscriptions.
     */
    public function testRepeatedFailuresOpenTheBreakerForThatHookAlone(): void
    {
        $this->installFixture();
        RecordingHandler::$shouldThrow = true;

        $hook = new HookDefinition(
            event: 'server.pre_delete',
            handler: 'RecordingHandler',
            mode: 'synchronous_best_effort',
        );

        $dispatcher = app(ExtensionHookDispatcher::class);

        for ($i = 0; $i < 5; ++$i) {
            $dispatcher->recordFailure('fixture_hooks', $hook, new \RuntimeException('nope'), 1);
        }

        $health = ExtensionHookHealth::query()->where('extension_id', 'fixture_hooks')->firstOrFail();

        $this->assertSame(1, $health->breaker_trips);
        $this->assertNotNull($health->breaker_open_until);
        $this->assertTrue($health->breaker_open_until->isFuture());
        $this->assertNull($health->quarantined_at, 'One trip must not quarantine the hook.');

        // The extension itself is untouched — it still loads and still holds
        // every other capability it declared.
        $this->assertNotNull(app(ExtensionRuntimePlanService::class)->entry('fixture_hooks'));

        // With the breaker open, delivery is skipped rather than retried.
        $server = $this->deletableServer();
        app(ServerDeletionService::class)->handle($server);

        $this->assertSame([], RecordingHandler::$calls);
        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
    }

    /** A long-lived dispatcher observes disablement without a manual flush. */
    public function testADisabledExtensionReceivesNothingWithoutFlushingTheRuntimePlan(): void
    {
        $this->installFixture();
        ExtensionConfig::query()->where('extension_id', 'fixture_hooks')->update(['enabled' => false]);

        $server = $this->deletableServer();

        app(ServerDeletionService::class)->handle($server);

        $this->assertSame([], RecordingHandler::$calls);
    }
}
