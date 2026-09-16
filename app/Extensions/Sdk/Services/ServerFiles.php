<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Models\Server;
use Psr\Http\Message\ResponseInterface;
use Everest\Repositories\Wings\DaemonFileRepository;

/**
 * Reading and writing a server's files through its daemon.
 *
 * Four verbs, which is every file operation the shipped packages perform. The
 * underlying DaemonFileRepository carries a wider surface — deletes, renames,
 * compression, chmod, pulls from arbitrary URLs — and a package that needs one
 * of those should be a conversation about adding it here, not a reason to
 * import the repository directly. That is the whole point of the boundary: the
 * supported surface grows deliberately.
 *
 * Authorization is *not* performed here. It happened at the edge, in the
 * FormRequest's `permission()`, against the core file permissions. This class
 * assumes the caller already proved it may act on this server.
 */
final class ServerFiles
{
    private function __construct(private DaemonFileRepository $repository)
    {
    }

    public static function for(Server $server): self
    {
        return new self(app(DaemonFileRepository::class)->setServer($server));
    }

    /**
     * @param int|null $notLargerThan maximum content length in bytes; the daemon
     *                                refuses larger files rather than streaming them
     */
    public function read(string $path, ?int $notLargerThan = null): string
    {
        return $this->repository->getContent($path, $notLargerThan);
    }

    public function write(string $path, string $content): ResponseInterface
    {
        return $this->repository->putContent($path, $content);
    }

    /** @return array<int, array<string, mixed>> */
    public function list(string $path): array
    {
        return $this->repository->getDirectory($path);
    }

    public function createDirectory(string $name, string $path): ResponseInterface
    {
        return $this->repository->createDirectory($name, $path);
    }
}
