<?php

namespace Everest\Http\Controllers\Api\Client;

use Illuminate\Http\Request;
use Everest\Facades\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Everest\Exceptions\DisplayException;
use Everest\Models\User;

class AccountDiscordController extends ClientApiController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get Discord OAuth URL for linking account.
     */
    public function getLinkUrl(Request $request): JsonResponse
    {
        if (!config('modules.auth.discord.enabled', false)) {
            throw new DisplayException('Discord integration is currently disabled.');
        }

        $state = encrypt([
            'user_id' => $request->user()->id,
            'action' => 'link',
            'timestamp' => time(),
        ]);

        $url = 'https://discord.com/api/oauth2/authorize?'
            . 'client_id=' . config('modules.auth.discord.client_id')
            . '&redirect_uri=' . route('auth.modules.discord.link.callback')
            . '&response_type=code&scope=identify%20email'
            . '&state=' . urlencode($state);

        return new JsonResponse([
            'url' => $url,
        ]);
    }

    /**
     * Handle the Discord OAuth callback for account linking.
     */
    public function handleCallback(Request $request)
    {
        if (!config('modules.auth.discord.enabled', false)) {
            return redirect('/account')->with('error', 'Discord integration is currently disabled.');
        }

        // Verify state parameter
        try {
            $state = decrypt($request->input('state'));
            
            // Verify this is a link action
            if (!isset($state['action']) || $state['action'] !== 'link') {
                throw new \Exception('Invalid state action');
            }

            // Verify timestamp is recent (within 10 minutes)
            if (!isset($state['timestamp']) || (time() - $state['timestamp']) > 600) {
                throw new \Exception('State expired');
            }

            $userId = $state['user_id'];
        } catch (\Exception $e) {
            return redirect('/account')->with('error', 'Invalid or expired Discord link request.');
        }

        // Exchange code for access token
        $response = Http::asForm()->post('https://discord.com/api/oauth2/token', [
            'client_id' => config('modules.auth.discord.client_id'),
            'client_secret' => config('modules.auth.discord.client_secret'),
            'grant_type' => 'authorization_code',
            'code' => $request->input('code'),
            'redirect_uri' => route('auth.modules.discord.link.callback'),
        ])->json();

        if (!isset($response['access_token'])) {
            return redirect('/account')->with('error', 'Failed to authenticate with Discord.');
        }

        // Get Discord user info
        $account = Http::withHeaders([
            'Authorization' => 'Bearer ' . $response['access_token'],
        ])->get('https://discord.com/api/users/@me')->json();

        if (!isset($account['id'])) {
            return redirect('/account')->with('error', 'Failed to retrieve Discord account information.');
        }

        // Check if this Discord account is already linked to another user
        $existingUser = User::where('external_id', $account['id'])
            ->where('id', '!=', $userId)
            ->first();

        if ($existingUser) {
            return redirect('/account')->with('error', 'This Discord account is already linked to another user.');
        }

        // Update the user's Discord info
        $user = User::findOrFail($userId);
        $user->update([
            'external_id' => $account['id'],
            'discord_username' => $account['username'] . '#' . ($account['discriminator'] ?? '0'),
            'discord_avatar' => $account['avatar'] ?? null,
        ]);

        Activity::event('user:discord.linked')
            ->subject($user)
            ->property('discord_id', $account['id'])
            ->property('discord_username', $account['username'])
            ->property('ip', $request->ip())
            ->property('user_agent', $request->userAgent())
            ->log();

        return redirect('/account')->with('success', 'Discord account linked successfully!');
    }

    /**
     * Unlink Discord account.
     */
    public function unlink(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->external_id && !$user->discord_username) {
            throw new DisplayException('No Discord account is currently linked.');
        }

        $discordUsername = $user->discord_username;

        $user->update([
            'external_id' => null,
            'discord_username' => null,
            'discord_avatar' => null,
        ]);

        Activity::event('user:discord.unlinked')
            ->property('previous_discord_username', $discordUsername)
            ->property('ip', $request->ip())
            ->property('user_agent', $request->userAgent())
            ->log();

        return new JsonResponse([
            'success' => true,
            'message' => 'Discord account unlinked successfully.',
        ]);
    }
}
