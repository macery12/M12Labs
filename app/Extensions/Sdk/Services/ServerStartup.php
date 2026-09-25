<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Models\Server;
use Everest\Services\Servers\StartupCommandService;
use Everest\Extensions\Hooks\Events\ServerUpdatedHook;
use Everest\Services\Extensions\ExtensionHookDispatcher;

/**
 * Reading and replacing a server's raw startup command.
 *
 * This class exists because writing `servers.startup` correctly is not one
 * statement. A package that assigns the column and calls save() -- which is
 * what minecraft_startup_editor did before this SDK -- leaves
 * `ServerUpdatedHook` undispatched, so every other extension subscribed to
 * `server.updated` silently misses the change. Going through here dispatches
 * it, after the write, exactly as core's own startup path does.
 *
 * **Read this before calling `replace()`.** In core, `servers.startup` is an
 * administrative field: StartupModificationService only writes it at
 * USER_LEVEL_ADMIN, and the client-facing StartupController edits egg
 * *variables* rather than the raw command. This method is reachable from a
 * client route, so the constraint that makes it safe is not a permission --
 * it is that the caller must never pass text the user supplied.
 *
 * A caller must:
 *
 *  - render the command server-side from a fixed, reviewed allowlist of
 *    options, so the worst a request can express is a different combination of
 *    flags the publisher already approved;
 *  - gate the endpoint on `Permission::ACTION_STARTUP_UPDATE`;
 *  - bound any resource figures against the server's own limits, so a request
 *    cannot produce a server that cannot boot.
 *
 * Accepting a startup command from request input and passing it here hands a
 * subuser arbitrary control over the container's command line. Do not.
 */
final class ServerStartup
{
    private function __construct(private Server $server)
    {
    }

    public static function for(Server $server): self
    {
        return new self($server);
    }

    /** The raw stored command, or null when the server uses its egg's default. */
    public function raw(): ?string
    {
        return $this->server->startup;
    }

    /** The command with panel and egg variables substituted, as the daemon will run it. */
    public function rendered(): string
    {
        return app(StartupCommandService::class)->handle($this->server);
    }

    public function usesEggDefault(): bool
    {
        return $this->server->startup === null || $this->server->startup === '';
    }

    /**
     * Replace the stored command. Null restores the egg's default.
     *
     * @param string|null $startup rendered server-side from a reviewed allowlist,
     *                             never taken from request input
     */
    public function replace(?string $startup): void
    {
        if ($this->server->startup === $startup) {
            return;
        }

        $this->server->startup = $startup;
        $this->server->save();

        // After the write, never before: a handler must not observe a state the
        // database has not committed to, and must not be able to prevent one
        // core has already accepted.
        app(ExtensionHookDispatcher::class)->dispatch(
            ServerUpdatedHook::fromServer($this->server->refresh(), ['startup'])
        );
    }
}
