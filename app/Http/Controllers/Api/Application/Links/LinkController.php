<?php

namespace Everest\Http\Controllers\Api\Application\Links;

use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Everest\Models\CustomLink;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Validation\ValidationException;
use Everest\Http\Requests\Api\Application\Links;
use Everest\Transformers\Api\Application\LinkTransformer;
use Everest\Exceptions\Http\QueryValueOutOfRangeHttpException;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;

class LinkController extends ApplicationApiController
{
    /**
     * LinkController constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get all links.
     */
    public function index(Links\GetLinksRequest $request): array
    {
        $perPage = (int) $request->query('per_page', '20');
        if ($perPage < 1 || $perPage > 100) {
            throw new QueryValueOutOfRangeHttpException('per_page', 1, 100);
        }

        $links = QueryBuilder::for(CustomLink::query())
            ->allowedFilters(...[
                AllowedFilter::exact('id'),
                'name',
                'url',
                'visible',
            ])
            ->allowedSorts(...['id', 'visible', 'name', 'sort'])
            ->defaultSort('sort', 'id')
            ->paginate($perPage);

        return $this->fractal->collection($links)
            ->transformWith(LinkTransformer::class)
            ->toArray();
    }

    /**
     * Create a new link.
     */
    public function store(Links\StoreLinkRequest $request): array
    {
        $link = CustomLink::create([
            'url' => $request['url'],
            'name' => $request['name'],
            'visible' => (bool) $request['visible'],
            'placement' => $request['placement'] ?? CustomLink::PLACEMENT_EVERYWHERE,
            // New links land at the bottom of the operator's order.
            'sort' => (int) CustomLink::query()->max('sort') + 1,
        ]);

        Activity::event('admin:link:create')
            ->property('name', $link->name)
            ->property('url', $link->url)
            ->description('New custom link for client UI was made')
            ->log();

        return $this->fractal->item($link)
            ->transformWith(LinkTransformer::class)
            ->toArray();
    }

    /**
     * Update a selected link.
     */
    public function update(Links\UpdateLinkRequest $request, int $id): Response
    {
        $link = CustomLink::findOrFail($id);
        $before = $link->only(['name', 'url', 'visible', 'placement']);

        $link->update([
            'url' => $request['url'],
            'name' => $request['name'],
            'visible' => (bool) $request['visible'],
            // Absent from API clients that predate placement: keep what is set.
            'placement' => $request['placement'] ?? $link->placement,
        ]);

        // Logged after the save and only for fields that moved, as
        // `old => new`. This used to log before saving and never recorded a
        // visibility change, which is the edit that actually affects users.
        $event = Activity::event('admin:link:update')
            ->property('name', $link->name)
            ->description('An existing custom link was updated');
        foreach ($before as $field => $old) {
            if ($link->wasChanged($field)) {
                $event->property($field, $this->describe($old) . ' => ' . $this->describe($link->{$field}));
            }
        }
        $event->log();

        return $this->returnNoContent();
    }

    /**
     * Reorder every link. The body lists all link ids in their new order; a
     * partial list is refused so two admins reordering at once can't leave
     * links with interleaved positions.
     */
    public function reorder(Links\ReorderLinksRequest $request): Response
    {
        /** @var list<int> $ids */
        $ids = array_map('intval', $request->input('ids'));

        $known = CustomLink::query()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $given = $ids;
        sort($given);
        if ($given !== $known) {
            throw ValidationException::withMessages(['ids' => 'The list must contain every link exactly once.']);
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $position => $id) {
                CustomLink::query()->whereKey($id)->update(['sort' => $position + 1]);
            }
        });

        Activity::event('admin:link:reorder')
            ->property('order', $ids)
            ->description('Custom links were reordered')
            ->log();

        return $this->returnNoContent();
    }

    /**
     * Delete a selected link.
     */
    public function delete(Links\DeleteLinkRequest $request, int $id): Response
    {
        $link = CustomLink::findOrFail($id);

        $link->delete();

        Activity::event('admin:link:delete')
            ->property('name', $link->name)
            ->property('url', $link->url)
            ->description('An existing custom link was deleted')
            ->log();

        return $this->returnNoContent();
    }

    private function describe(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
