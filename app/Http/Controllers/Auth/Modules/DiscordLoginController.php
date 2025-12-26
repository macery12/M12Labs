<?php

namespace Everest\Http\Controllers\Auth\Modules;

use Everest\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\RedirectResponse;
use Everest\Services\Users\UserCreationService;
use Everest\Http\Controllers\Auth\AbstractLoginController;
use Everest\Contracts\Repository\SettingsRepositoryInterface;

class DiscordLoginController extends AbstractLoginController
{
    /**
     * DiscordLoginController constructor.
     */
    public function __construct(
        private UserCreationService $creationService,
        private SettingsRepositoryInterface $settings,
    ) {
        parent::__construct();
    }

    /**
     * Get the user's Discord token in order to access the account.
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

        return 'https://discord.com/api/oauth2/authorize?'
            . 'client_id=' . config('modules.auth.discord.client_id')
            . '&redirect_uri=' . route('auth.modules.discord.authenticate')
            . '&response_type=code&scope=identify%20email'
            . '&state=' . encrypt($request->ip());
    }

    /**
     * Authenticate with the Discord OAuth2 service.
     */
    public function authenticate(Request $request): RedirectResponse
    {
        $tokenResponse = Http::asForm()->post('https://discord.com/api/oauth2/token', [
            'client_id' => config('modules.auth.discord.client_id'),
            'client_secret' => config('modules.auth.discord.client_secret'),
            'grant_type' => 'authorization_code',
            'code' => $request->input('code'),
            'redirect_uri' => route('auth.modules.discord.authenticate'),
        ])->body();

        $response = json_decode($tokenResponse);

        // Check if token exchange was successful
        if (!isset($response->access_token)) {
            return redirect()->route('auth.login');
        }

        $accountResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $response->access_token,
        ])->get('https://discord.com/api/users/@me')->body();

        $account = json_decode($accountResponse);

        // Check if account data was successfully retrieved
        if (!isset($account->email)) {
            return redirect()->route('auth.login');
        }

        if (User::where('email', $account->email)->exists()) {
            $user = User::where('email', $account->email)->first();

            $this->sendLoginResponse($user, $request);

            return redirect('/');
        } else {
            $username = $this->generateUniqueUsername($account->username ?? $account->global_name ?? 'user');
            $user = $this->createAccount(['email' => $account->email, 'username' => $username]);

            $this->sendLoginResponse($user, $request);

            return redirect('/account/setup');
        }

        return redirect()->route('auth.login');
    }

    /**
     * Generate a unique username from Discord username.
     * Sanitizes the username to meet validation requirements and ensures uniqueness.
     */
    private function generateUniqueUsername(string $discordUsername): string
    {
        // Sanitize the username: lowercase, remove invalid characters, ensure it starts/ends with alphanumeric
        $username = mb_strtolower($discordUsername);
        $username = preg_replace('/[^a-z0-9\._-]/', '', $username);
        
        // Ensure username starts and ends with alphanumeric character
        $username = preg_replace('/^[^a-z0-9]+/', '', $username);
        $username = preg_replace('/[^a-z0-9]+$/', '', $username);
        
        // If username is empty or too short after sanitization, use a default
        if (strlen($username) < 2) {
            $username = 'discord_user';
        }
        
        // Limit username length to reasonable size (max 191 as per validation)
        $username = substr($username, 0, 100);
        
        // Check if username is unique, if not append random suffix
        $originalUsername = $username;
        $attempts = 0;
        while (User::where('username', $username)->exists() && $attempts < 10) {
            $username = $originalUsername . '_' . $this->randStr(6);
            $attempts++;
        }
        
        // Final fallback if still not unique
        if (User::where('username', $username)->exists()) {
            $username = 'user_' . $this->randStr(16);
        }
        
        return $username;
    }

    /**
     * Create a random string we can use for a temporary username.
     */
    private function randStr(int $length = 10): string
    {
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
    }
}
