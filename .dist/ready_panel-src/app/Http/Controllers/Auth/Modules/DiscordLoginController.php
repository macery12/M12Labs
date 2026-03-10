<?php

namespace Everest\Http\Controllers\Auth\Modules;

use Carbon\Carbon;
use Everest\Models\User;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\RedirectResponse;
use Everest\Exceptions\DisplayException;
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

        $state = Str::random(40);
        $request->session()->put('discord_oauth_state', $state);

        return 'https://discord.com/api/oauth2/authorize?'
            . 'client_id=' . config('modules.auth.discord.client_id')
            . '&redirect_uri=' . route('auth.modules.discord.authenticate')
            . '&response_type=code&scope=identify%20email'
            . '&state=' . $state;
    }

    /**
     * Show the Discord registration form with pre-filled data from session.
     */
    public function showRegistrationForm(Request $request)
    {
        $discordData = $request->session()->get('discord_registration_data');

        if (!$discordData) {
            return redirect()->route('auth.login')->with('error', 'Discord registration data not found. Please try again.');
        }

        // Return to the React frontend which will handle the form
        return redirect('/auth/discord/register');
    }

    /**
     * Complete the Discord registration with user-provided details.
     *
     * @throws \Everest\Exceptions\DisplayException
     */
    public function completeRegistration(Request $request): JsonResponse
    {
        $discordData = $request->session()->get('discord_registration_data');

        if (!$discordData) {
            throw new DisplayException('Discord registration data not found. Please try again.');
        }

        // Check if Discord SSO is enabled
        if (!config('modules.auth.discord.enabled', false)) {
            throw new DisplayException('Discord authentication is currently disabled.');
        }

        $username = $request->input('username');
        $password = $request->input('password');
        $passwordConfirm = $request->input('confirm_password');

        if (!$username) {
            throw new DisplayException('Username is required.');
        }

        // Password is always required for SFTP access
        if (!$password || !$passwordConfirm) {
            throw new DisplayException('Password and password confirmation are required.');
        }

        if ($password !== $passwordConfirm) {
            throw new DisplayException('The passwords entered do not match.');
        }

        if (User::where('username', $username)->exists()) {
            throw new DisplayException('This username is already in use.');
        }

        // Create the user with Discord data (bypassing regular registration check)
        $userData = [
            'username' => $username,
            'email' => $discordData['discord_email'],
            'external_id' => $discordData['discord_id'],
            'password' => $password,
        ];

        // Create user directly via UserCreationService, bypassing registration.enabled check
        $user = $this->creationService->handle($userData);

        // Apply jguard delay if configured
        $delay = (int) config('modules.auth.jguard.delay') ?? 0;
        $guard = config('modules.auth.jguard.enabled') ?? false;

        if ($guard || $delay > 0) {
            DB::table('jguard_delay')->insert([
                'user_id' => $user->id,
                'expires_at' => Carbon::now()->add($delay, 'minute'),
            ]);
        }

        // Clear the session data
        $request->session()->forget('discord_registration_data');

        // Log the user in (new registrations won't have 2FA enabled)
        return $this->sendLoginResponse($user, $request);
    }

    /**
     * Get Discord registration data from session.
     */
    public function getRegistrationData(Request $request): JsonResponse
    {
        $discordData = $request->session()->get('discord_registration_data');

        if (!$discordData) {
            return response()->json(['error' => 'No Discord registration data found'], 404);
        }

        return response()->json([
            'discord_username' => $discordData['discord_username'],
            'discord_email' => $discordData['discord_email'],
            'discord_id' => $discordData['discord_id'],
        ]);
    }

    /**
     * Check if a username is available.
     */
    public function checkUsername(Request $request): JsonResponse
    {
        $username = $request->input('username');

        if (!$username) {
            return response()->json(['available' => false, 'message' => 'Username is required'], 400);
        }

        $exists = User::where('username', $username)->exists();

        return response()->json([
            'available' => !$exists,
            'message' => $exists ? 'This username is already taken' : 'Username is available',
        ]);
    }

    /**
     * Authenticate with the Discord OAuth2 service.
     */
    public function authenticate(Request $request): RedirectResponse
    {
        // Validate OAuth state parameter to prevent CSRF attacks
        $sessionState = $request->session()->pull('discord_oauth_state');
        $returnedState = $request->input('state');

        if (!$sessionState || !$returnedState || !hash_equals($sessionState, $returnedState)) {
            return redirect()->route('auth.login')->with('error', 'Invalid OAuth state. Please try again.');
        }

        $response = Http::asForm()->post('https://discord.com/api/oauth2/token', [
            'client_id' => config('modules.auth.discord.client_id'),
            'client_secret' => config('modules.auth.discord.client_secret'),
            'grant_type' => 'authorization_code',
            'code' => $request->input('code'),
            'redirect_uri' => route('auth.modules.discord.authenticate'),
        ])->body();

        $response = json_decode($response);

        if (!isset($response->access_token)) {
            return redirect()->route('auth.login')->with('error', 'Failed to authenticate with Discord.');
        }

        $account = Http::withHeaders([
            'Authorization' => 'Bearer ' . $response->access_token,
        ])->asForm()->get('https://discord.com/api/users/@me')->body();

        $account = json_decode($account);

        if (!isset($account->id)) {
            return redirect()->route('auth.login')->with('error', 'Failed to retrieve Discord account information.');
        }

        // Check if user exists with this Discord ID (external_id)
        $user = User::where('external_id', $account->id)->first();

        if ($user) {
            // If user has 2FA enabled, redirect to login for TOTP verification
            if ($user->use_totp) {
                return $this->redirectToTwoFactorChallenge($request, $user);
            }

            // User exists with this Discord ID, log them in
            $loginResponse = $this->sendLoginResponse($user, $request);
            $redirect = redirect('/');

            foreach ($loginResponse->headers->getCookies() as $cookie) {
                $redirect->headers->setCookie($cookie);
            }

            return $redirect;
        }

        // Check if user exists with the same email
        $existingEmailUser = User::where('email', $account->email)->first();

        if ($existingEmailUser) {
            // Email exists but not linked to Discord
            // For security, we don't auto-link - user should manually link in account settings
            return redirect()->route('auth.login')->with(
                'error',
                'An account with this email already exists. Please login with your password to link your Discord account.'
            );
        }

        // New user - store Discord data in session and redirect to registration
        $request->session()->put('discord_registration_data', [
            'discord_id' => $account->id,
            'discord_username' => $account->username,
            'discord_email' => $account->email,
            'discord_avatar' => $account->avatar ?? null,
        ]);

        return redirect('/auth/discord/register');
    }

    /**
     * Redirect a user with 2FA enabled to the TOTP verification challenge.
     * Stores the pending authentication in session for the checkpoint flow.
     */
    protected function redirectToTwoFactorChallenge(Request $request, User $user): RedirectResponse
    {
        $token = Str::random(64);

        $request->session()->put('auth_confirmation_token', [
            'user_id' => $user->id,
            'token_value' => $token,
            'expires_at' => CarbonImmutable::now()->addMinutes(5),
        ]);

        return redirect('/auth/login?checkpoint=' . $token);
    }

    /**
     * Create a random string we can use for a temporary username.
     */
    public function randStr(int $length = 10): string
    {
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
    }
}
