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

        // Generate a CSRF token for OAuth state parameter
        $state = bin2hex(random_bytes(16));
        $request->session()->put('oauth_state', $state);

        return 'https://discord.com/api/oauth2/authorize?'
            . 'client_id=' . config('modules.auth.discord.client_id')
            . '&redirect_uri=' . urlencode(route('auth.modules.discord.authenticate'))
            . '&response_type=code&scope=identify%20email'
            . '&state=' . $state;
    }

    /**
     * Authenticate with the Discord OAuth2 service.
     */
    public function authenticate(Request $request): RedirectResponse
    {
        // Validate OAuth state to prevent CSRF attacks
        $state = $request->input('state');
        $sessionState = $request->session()->get('oauth_state');
        
        if (!$state || !$sessionState || $state !== $sessionState) {
            return redirect()->route('auth.login');
        }
        
        // Clear the state from session
        $request->session()->forget('oauth_state');

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

            // Link Discord account if not already linked with Discord
            if (isset($account->id) && strpos($user->external_id ?? '', 'discord:') !== 0) {
                $user->external_id = 'discord:' . $account->id;
                $user->save();
            }

            $this->sendLoginResponse($user, $request);

            return redirect('/');
        } else {
            // Store Discord info in session for account setup
            $discordUsername = $this->sanitizeDiscordUsername($account->username ?? $account->global_name ?? 'user');
            $discordId = isset($account->id) ? 'discord:' . $account->id : null;
            
            $request->session()->put('oauth_account_data', [
                'email' => $account->email,
                'username' => $discordUsername,
                'external_id' => $discordId,
                'provider' => 'discord',
            ]);

            // Create temporary account that will be finalized during setup
            $username = 'pending_' . $this->randStr(16);
            $user = $this->createAccount([
                'email' => $account->email,
                'username' => $username,
                'external_id' => $discordId,
            ]);

            $this->sendLoginResponse($user, $request);

            return redirect('/account/setup');
        }

        return redirect()->route('auth.login');
    }

    /**
     * Sanitize a Discord username to meet validation requirements.
     * Does not check for uniqueness - that's handled during account setup.
     */
    private function sanitizeDiscordUsername(string $discordUsername): string
    {
        // Sanitize the username: lowercase, remove invalid characters
        $username = mb_strtolower($discordUsername);
        // Remove any characters that aren't alphanumeric, dots, underscores, or hyphens
        $username = preg_replace('/[^a-z0-9._\-]/', '', $username);
        
        // Ensure username starts and ends with alphanumeric character
        $username = preg_replace('/^[^a-z0-9]+/', '', $username);
        $username = preg_replace('/[^a-z0-9]+$/', '', $username);
        
        // If username is empty or too short after sanitization, use a default
        if (strlen($username) < 2) {
            $username = 'discorduser';
        }
        
        // Limit username length to reasonable size
        $username = substr($username, 0, 100);
        
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
