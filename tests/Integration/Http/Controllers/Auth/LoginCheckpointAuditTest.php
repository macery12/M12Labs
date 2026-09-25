<?php

namespace Everest\Tests\Integration\Http\Controllers\Auth;

use Everest\Events\ActivityLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Session;
use Everest\Tests\Integration\IntegrationTestCase;

/**
 * End to end, without faking Failed: a checkpoint failure that names no account
 * still reaches AuthenticationListener and is written as auth:fail.
 * LoginCheckpointControllerTest fakes the event, so it cannot show this.
 */
class LoginCheckpointAuditTest extends IntegrationTestCase
{
    public function testMismatchedConfirmationTokenIsAudited(): void
    {
        Session::put('auth_confirmation_token', [
            'user_id' => 1,
            'token_value' => 'token',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->postJson(route('auth.login-checkpoint', [
            'confirmation_token' => 'wrong-token',
            'authentication_code' => '123456',
        ]))->assertBadRequest();

        $this->assertActivityLogged('auth:fail');
        Event::assertDispatched(ActivityLogged::class, fn (ActivityLogged $e) => $e->is('auth:fail')
            && $e->model->actor_id === null
            && $e->model->subjects->isEmpty()
            // Request metadata only: neither 2FA secret is recorded.
            && !$e->model->properties->has('confirmation_token')
            && !$e->model->properties->has('authentication_code')
            && $e->model->properties->get('ip') === '127.0.0.1');
    }
}
