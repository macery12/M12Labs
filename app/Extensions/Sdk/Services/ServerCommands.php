<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Models\Server;
use Psr\Http\Message\ResponseInterface;
use Everest\Repositories\Wings\DaemonCommandRepository;

/**
 * Sending console commands to a running server.
 *
 * As with ServerFiles, authorization belongs to the FormRequest that admitted
 * the request — `Permission::ACTION_CONTROL_CONSOLE` for anything here.
 *
 * Worth stating plainly because it is easy to forget when a package builds a
 * command from user input: the daemon executes whatever string it is given, in
 * the game server's own console. Everything a package interpolates into a
 * command is therefore a parameter to a shell-like interpreter, and must be
 * validated in the FormRequest rather than sanitised here — this class has no
 * idea what game is running or what its console syntax is.
 */
final class ServerCommands
{
    private function __construct(private DaemonCommandRepository $repository)
    {
    }

    public static function for(Server $server): self
    {
        return new self(app(DaemonCommandRepository::class)->setServer($server));
    }

    public function send(string|array $command): ResponseInterface
    {
        return $this->repository->send($command);
    }
}
