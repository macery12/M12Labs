<?php

namespace Everest\Tests\Integration\Services\Auth;

use Everest\Models\User;
use Carbon\CarbonImmutable;
use Everest\Models\UserSession;
use Illuminate\Support\Facades\DB;
use Everest\Services\Auth\UserSessionService;
use Everest\Tests\Integration\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * UpdateUserSessionActivity runs on every authenticated web and client API
 * request, and the dashboard fans out several API calls per page. It used to
 * re-read the session row the middleware had just read and write it twice,
 * every time. It now reuses that row and writes only when the activity stamp
 * is over a minute old or the connection details changed.
 */
class UserSessionActivityTest extends IntegrationTestCase
{
    use DatabaseTransactions;

    private const DEVICE_ID = 'activity-test-device';

    private User $user;

    private UserSessionService $service;

    public function setUp(): void
    {
        parent::setUp();

        request()->cookies->set(UserSessionService::DEVICE_COOKIE, self::DEVICE_ID);
        request()->server->set('REMOTE_ADDR', '203.0.113.10');
        request()->headers->set('User-Agent', 'ActivityTest/1.0');

        $this->user = User::factory()->create();
        $this->service = app(UserSessionService::class);
    }

    private function trackedSession(CarbonImmutable $lastActivity): UserSession
    {
        $deviceId = self::DEVICE_ID;
        $this->service->recordLogin($this->user, 'activity-session', $deviceId);

        $session = UserSession::query()
            ->where('user_id', $this->user->id)
            ->where('session_id', 'activity-session')
            ->firstOrFail();
        $session->forceFill(['last_activity_at' => $lastActivity])->save();

        return $session->refresh();
    }

    /**
     * @return list<string>
     */
    private function writesDuring(callable $callback): array
    {
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(update|insert|delete)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $callback();

        return $writes;
    }

    public function testARecentlyActiveUnchangedSessionIsNotWritten(): void
    {
        $session = $this->trackedSession(CarbonImmutable::now()->subSeconds(10));

        $writes = $this->writesDuring(fn () => $this->service->updateActivity($this->user, 'activity-session', $session));

        $this->assertSame([], $writes);
    }

    public function testAStaleSessionIsStamped(): void
    {
        $session = $this->trackedSession(CarbonImmutable::now()->subMinutes(5));

        $this->service->updateActivity($this->user, 'activity-session', $session);

        $this->assertTrue($session->refresh()->last_activity_at->greaterThan(CarbonImmutable::now()->subSeconds(5)));
    }

    public function testANewIpAddressIsRecordedImmediately(): void
    {
        $session = $this->trackedSession(CarbonImmutable::now()->subSeconds(10));
        request()->server->set('REMOTE_ADDR', '198.51.100.7');

        $this->service->updateActivity($this->user, 'activity-session', $session);

        $this->assertSame('198.51.100.7', $session->refresh()->ip_address);
    }

    /**
     * The row is read before the request runs. A request that revokes its own
     * session must stay revoked when the stale copy is written back after it.
     */
    public function testARevocationDuringTheRequestIsNotUndone(): void
    {
        $session = $this->trackedSession(CarbonImmutable::now()->subMinutes(5));
        UserSession::query()->whereKey($session->id)->update(['revoked_at' => CarbonImmutable::now()]);

        $this->service->updateActivity($this->user, 'activity-session', $session);

        $this->assertNotNull($session->refresh()->revoked_at);
    }
}
