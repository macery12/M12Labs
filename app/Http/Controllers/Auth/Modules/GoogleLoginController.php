<?php

namespace Everest\Http\Controllers\Auth\Modules;

use Everest\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Everest\Http\Controllers\Auth\AbstractLoginController;

class GoogleLoginController extends AbstractLoginController
{
    protected array $config;

    /**
     * GoogleLoginController constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->config = [
            'redirect' => route('auth.modules.google.authenticate'),
            'client_id' => config('modules.auth.google.client_id'),
            'client_secret' => config('modules.auth.google.client_secret'),
        ];
    }

    /**
     * Get the user's Google details in order to access the account.
     *
     * @throws \Everest\Exceptions\DisplayException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function requestToken(Request $request): string
    {
        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);
            $this->sendLockoutResponse($request);
        }

        return Socialite::buildProvider(GoogleProvider::class, $this->config)
            ->redirect()
            ->getTargetUrl();
    }

    /**
     * Authenticate with the Google OAuth2 service.
     */
    public function authenticate(Request $request): RedirectResponse
    {
        $response = Socialite::buildProvider(GoogleProvider::class, $this->config)->user();

        if (User::where('email', $response->email)->exists()) {
            $user = User::where('email', $response->email)->first();

            $this->sendLoginResponse($user, $request);

            return redirect('/');
        }
        $user = $this->createAccount(['email' => $response->email, 'username' => 'null_user_' . $this->randStr(16)]);

        $this->sendLoginResponse($user, $request);

        return redirect('/account/setup');

    }

    /**
     * Create a random string we can use for a temporary username.
     */
    public function randStr(int $length = 10): string
    {
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
    }
}
