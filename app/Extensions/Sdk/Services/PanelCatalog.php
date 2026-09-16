<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Models\Egg;
use Everest\Models\Nest;
use Illuminate\Support\Collection;

/**
 * Reading the nest and egg catalog.
 *
 * For a package whose admin page lets an operator scope a feature to particular
 * game types -- which nests and eggs a domain may be offered on, which eggs a
 * tool understands. Read-only: the catalog is core's, and an egg edited by an
 * extension would change what every server built from it runs.
 *
 * Both methods select explicit columns. An Egg row carries its full startup
 * command, its install script and its Docker image list, none of which a
 * picker needs and all of which are noise in an admin API response.
 */
final class PanelCatalog
{
    private function __construct()
    {
    }

    public static function reader(): self
    {
        return new self();
    }

    /** @return Collection<int, Nest> */
    public function nests(): Collection
    {
        return Nest::query()
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'description']);
    }

    /** @return Collection<int, Egg> */
    public function eggs(): Collection
    {
        return Egg::query()
            ->with('nest:id,name')
            ->orderBy('name')
            ->get(['id', 'uuid', 'nest_id', 'name', 'description']);
    }

    /** @return Collection<int, Egg> */
    public function eggsInNest(int $nestId): Collection
    {
        return Egg::query()
            ->where('nest_id', $nestId)
            ->orderBy('name')
            ->get(['id', 'uuid', 'nest_id', 'name', 'description']);
    }
}
