<?php

namespace Everest\Http\Middleware\Api\Client;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the server marketplace API on the admin's module switches.
 *
 * The frontend already hides the marketplace when it is off, but until this
 * existed nothing on the server did: mod search worked with the module
 * disabled, and a modpack install needed only a stored CurseForge key — the
 * admin's "CurseForge enabled" switch was cosmetic to anyone calling the API
 * directly.
 *
 * `marketplace` requires the mods module; `marketplace:modpacks` additionally
 * requires CurseForge to be switched on and configured, which is the same
 * condition the frontend uses to show the Modpacks tab. Both read config(),
 * which SettingsServiceProvider hydrates from the stored settings at boot (the
 * API key arrives there as a has-a-value boolean, never the secret).
 *
 * A switched-off module answers 404, like a disabled extension does.
 */
class EnsureMarketplaceEnabled
{
    public function handle(Request $request, \Closure $next, ?string $surface = null): Response
    {
        if (!self::modsEnabled()) {
            return response('', 404);
        }

        if ($surface === 'modpacks' && !self::modpacksEnabled()) {
            return response('', 404);
        }

        return $next($request);
    }

    public static function modsEnabled(): bool
    {
        return (bool) config('modules.mods.enabled', false);
    }

    public static function modpacksEnabled(): bool
    {
        return self::modsEnabled()
            && (bool) config('modules.mods.curseforge_enabled', false)
            && (bool) config('modules.mods.curseforge_api_key', false);
    }
}
