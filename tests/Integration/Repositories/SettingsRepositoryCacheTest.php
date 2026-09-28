<?php

namespace Everest\Tests\Integration\Repositories;

use Everest\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\CallQueuedClosure;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Repositories\Eloquent\SettingsRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Contracts\Repository\SettingsRepositoryInterface;

/**
 * Boot reads every settings row to overlay config; that read now primes the
 * repository, so the per-key SELECTs an HTML render used to make (29 of them)
 * are gone, secrets are no longer decrypted on every boot, and a long-lived
 * worker re-reads settings per job instead of keeping its first answer for an
 * hour.
 */
class SettingsRepositoryCacheTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    public static mixed $seenInJob = null;

    private SettingsRepositoryInterface $settings;

    public function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingsRepositoryInterface::class);
    }

    public function tearDown(): void
    {
        SettingsRepository::flushCache();

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function queriesDuring(callable $callback): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $callback();

        return $queries;
    }

    public function testAfterTheBootLoadNoKeyIsQueriedAgainPresentOrNot(): void
    {
        Setting::set('settings::app:name', 'Cached Panel');
        $this->settings->loadAll();

        $queries = $this->queriesDuring(function () {
            $this->assertSame('Cached Panel', Setting::get('settings::app:name'));
            $this->assertSame('fallback', Setting::get('settings::no:such:key', 'fallback'));
        });

        $this->assertSame([], $queries);
    }

    public function testTheBootLoadLeavesSecretsEncryptedUntilRead(): void
    {
        Setting::set('settings::modules:email:smtp:password', 'hunter2-smtp');

        $raw = $this->settings->loadAll();

        $this->assertNotSame('hunter2-smtp', $raw['settings::modules:email:smtp:password']);
        $this->assertNotEmpty($raw['settings::modules:email:smtp:password']);
        $this->assertSame('hunter2-smtp', Setting::get('settings::modules:email:smtp:password'));
    }

    /** A value written by another process reaches the next job, not the next worker. */
    public function testEachQueueJobReadsSettingsAfresh(): void
    {
        Setting::set('settings::app:name', 'Before');
        $this->assertSame('Before', Setting::get('settings::app:name'));

        // Written by the admin's web request, which this process never sees.
        DB::table('settings')->where('key', 'settings::app:name')->update(['value' => 'After']);
        $this->assertSame('Before', Setting::get('settings::app:name'));

        Queue::connection('sync')->push(CallQueuedClosure::create(function (): void {
            SettingsRepositoryCacheTest::$seenInJob = Setting::get('settings::app:name');
        }));

        $this->assertSame('After', self::$seenInJob);
    }
}
