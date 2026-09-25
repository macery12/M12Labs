<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Models\Server;
use Psr\Http\Message\ResponseInterface;
use Everest\Repositories\Wings\DaemonFileRepository;

/**
 * Reading and writing a server's files through its daemon.
 *
 * Five verbs, which is every file operation the shipped packages perform. The
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

    /**
     * Stream a local file to the server without holding it in memory.
     *
     * For anything a package downloaded before writing — a plugin jar, an
     * archive. The daemon is handed bytes the panel has already fetched and
     * checked, never a URL, which is what keeps it from resolving a host or
     * following a redirect on a caller's behalf.
     *
     * @param string $temporaryPath a local path the panel controls; never a
     *                              path derived from request input
     */
    public function writeFrom(string $path, string $temporaryPath): ResponseInterface
    {
        return $this->repository->putFile($path, $temporaryPath);
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
