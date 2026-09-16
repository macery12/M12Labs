<?php

namespace Everest\Tests\Unit\Services\Access;

use Everest\Models\User;
use Everest\Tests\TestCase;
use Illuminate\Http\Request;
use Everest\Services\Access\InternalRequest;
use Everest\Services\Access\InternalDispatch;
use Everest\Exceptions\Service\Access\InternalDispatchException;

/**
 * Running a request through the panel's own pipeline as the acting user.
 *
 * This is the mechanism that lets a caller reuse the panel's authorization
 * instead of growing a second copy of it — and for the same reason it is a
 * confused-deputy generator, which is why an extension reaches it only through
 * an approved `capabilities.privileged` grant.
 *
 * The cases here are the constraints that make the dispatch safe at all, each
 * of which has been a real failure at some point: what the sub-request is not
 * allowed to carry, what it must carry, and when it must refuse to run.
 */
class InternalDispatchTest extends TestCase
{
    private function build(InternalRequest $request, ?Request $parent = null): Request
    {
        $parent ??= Request::create('https://panel.test/api/client', 'GET');

        return (new \ReflectionMethod(InternalDispatch::class, 'buildSubRequest'))
            ->invoke(app(InternalDispatch::class), $request, $parent);
    }

    /*
    |--------------------------------------------------------------------------
    | The marker
    |--------------------------------------------------------------------------
    */

    /**
     * `Request::$attributes` is a server-side bag that neither
     * `createFromGlobals()` nor `createFromBase()` ever populates from the wire,
     * and the marker is compared by object identity — so even something able to
     * write to the bag could not produce the instance.
     */
    public function testTheMarkerCannotBeForgedFromOutside(): void
    {
        $plain = Request::create('/api/client/servers/x/activity', 'GET');
        $this->assertFalse(InternalDispatch::isInternal($plain));

        $spoofed = Request::create('/api/client/servers/x/activity', 'GET', [
            'everest.internal_dispatch' => true,
        ]);
        $spoofed->headers->set('everest.internal_dispatch', '1');
        $spoofed->attributes->set('everest.internal_dispatch', true);

        $this->assertFalse(InternalDispatch::isInternal($spoofed));
    }

    public function testADispatchedRequestCarriesTheMarker(): void
    {
        $this->assertTrue(InternalDispatch::isInternal(
            $this->build(new InternalRequest('GET', '/api/client/servers/x/activity')),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | What the sub-request carries
    |--------------------------------------------------------------------------
    */

    /**
     * Re-sending already-decrypted cookies makes EncryptCookies fail, nulling
     * the session cookie and regenerating the id on the *shared* store, which
     * logs the user out mid-request. Identity travels by the resolver instead.
     */
    public function testCredentialBearingHeadersAreStripped(): void
    {
        $parent = Request::create('https://panel.test/api/client', 'GET');
        $parent->headers->set('Cookie', 'everest_session=abc');
        $parent->headers->set('Authorization', 'Bearer ptlc_secret');
        $parent->headers->set('X-CSRF-TOKEN', 'token');

        $sub = $this->build(new InternalRequest('GET', '/api/client/servers/x/activity'), $parent);

        foreach (['Cookie', 'Authorization', 'Referer', 'Origin', 'X-XSRF-TOKEN', 'X-CSRF-TOKEN'] as $header) {
            $this->assertFalse($sub->headers->has($header), $header . ' must not travel to the sub-request.');
        }
        $this->assertSame([], $sub->cookies->all());
    }

    public function testTheActingUserTravelsByResolverRatherThanByCredential(): void
    {
        $user = User::factory()->make(['id' => 9]);
        $parent = Request::create('https://panel.test/api/client', 'GET');
        $parent->setUserResolver(fn () => $user);

        $sub = $this->build(new InternalRequest('GET', '/api/client/servers/x/activity'), $parent);

        $this->assertSame($user, $sub->user());
    }

    /**
     * Mandatory: it is what makes the exception handler emit the JSON envelope
     * instead of a 302 for a ValidationException or an HTML page for an
     * HttpException.
     */
    public function testJsonIsDemandedSoErrorsComeBackStructured(): void
    {
        $sub = $this->build(new InternalRequest('GET', '/api/client/servers/x/activity'));

        $this->assertSame('application/json', $sub->headers->get('Accept'));
        $this->assertSame('XMLHttpRequest', $sub->headers->get('X-Requested-With'));
    }

    public function testTheUriIsAbsoluteSoSignedNodeUrlsSurviveTheRebind(): void
    {
        $sub = $this->build(new InternalRequest('GET', '/api/client/servers/x/files/list', ['directory' => '/logs']));

        $this->assertSame('https://panel.test/api/client/servers/x/files/list', $sub->url());
        $this->assertSame('/logs', $sub->query('directory'));
    }

    public function testAWriteCarriesAJsonBodyAndAnIdempotencyKey(): void
    {
        $sub = $this->build(new InternalRequest(
            method: 'POST',
            uri: '/api/client/servers/x/files/write',
            body: ['file' => '/server.properties', 'contents' => 'motd=hi'],
            idempotencyKey: 'call-17',
        ));

        $this->assertSame('application/json', $sub->headers->get('Content-Type'));
        $this->assertSame('call-17', $sub->headers->get('Idempotency-Key'));
        $this->assertSame('/server.properties', $sub->json('file'));
    }

    /*
    |--------------------------------------------------------------------------
    | When it refuses
    |--------------------------------------------------------------------------
    */

    /**
     * The exception handler calls `rollBack(0)` when it renders, so a failure
     * inside the sub-request would silently discard the caller's own work.
     */
    public function testDispatchingInsideATransactionIsRefused(): void
    {
        $db = \Mockery::mock(\Illuminate\Database\DatabaseManager::class);
        $db->shouldReceive('transactionLevel')->once()->andReturn(1);

        $dispatch = new InternalDispatch(app(), $db);

        try {
            $dispatch->dispatch(new InternalRequest('GET', '/api/client'));
            $this->fail('A dispatch inside a transaction should be refused.');
        } catch (InternalDispatchException $e) {
            $this->assertSame(InternalDispatchException::REASON_TRANSACTION, $e->reason);
        }
    }

    /**
     * Callers act on the three refusals differently — a deadline is worth
     * retrying with less work, a transaction is a caller bug, and an unreadable
     * response means this endpoint can never be reached this way.
     */
    public function testEachRefusalIsDistinguishable(): void
    {
        $this->assertSame(
            ['deadline', 'transaction', 'unreadable', 'forbidden'],
            [
                InternalDispatchException::deadlineElapsed()->reason,
                InternalDispatchException::insideTransaction()->reason,
                InternalDispatchException::unreadableResponse()->reason,
                InternalDispatchException::notGranted('demo')->reason,
            ],
        );
    }
}
