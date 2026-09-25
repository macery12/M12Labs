<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Models\Node;
use Illuminate\Support\Collection;
use Everest\Repositories\Wings\DaemonConfigurationRepository;

/**
 * Reading the panel's nodes, and asking one about itself.
 *
 * Read-only by design. Creating, editing or deleting a node is an operator
 * action with consequences for every server on it, and nothing an extension
 * should reach around the admin UI to do.
 *
 * `names()` and `all()` are separate because enumerating every Node model to
 * build a lookup table is the common case and does not need the models. A
 * package polling nodes on a schedule will do this on every tick.
 */
final class PanelNodes
{
    private function __construct()
    {
    }

    public static function reader(): self
    {
        return new self();
    }

    /** @return Collection<int, Node> */
    public function all(): Collection
    {
        return Node::query()->get();
    }

    /**
     * Node id => display name, for labelling rows a package stored by id.
     *
     * @return Collection<int, string>
     */
    public function names(): Collection
    {
        return Node::query()->pluck('name', 'id');
    }

    public function find(int $id): ?Node
    {
        return Node::query()->find($id);
    }

    /**
     * Ask a node's daemon what it is running on.
     *
     * Throws DaemonConnectionException when the node is unreachable, which for
     * a health poller is the answer rather than an error -- catch it.
     *
     * @return array<string, mixed>
     */
    public function systemInformation(Node $node, ?int $version = null): array
    {
        return app(DaemonConfigurationRepository::class)->setNode($node)->getSystemInformation($version);
    }
}
