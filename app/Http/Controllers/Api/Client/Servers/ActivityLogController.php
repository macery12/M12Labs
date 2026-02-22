<?php

namespace Everest\Http\Controllers\Api\Client\Servers;

use Everest\Models\User;
use Everest\Models\Server;
use Everest\Models\Permission;
use Everest\Models\ActivityLog;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Everest\Http\Requests\Api\Client\ClientApiRequest;
use Everest\Transformers\Api\Client\ActivityLogTransformer;
use Everest\Http\Controllers\Api\Client\ClientApiController;

class ActivityLogController extends ClientApiController
{
    public function __invoke(ClientApiRequest $request, Server $server): array
    {
        $this->authorize(Permission::ACTION_ACTIVITY_READ, $server);

        $activity = QueryBuilder::for($server->activity())
            ->with('actor')
            ->allowedSorts(['timestamp'])
            ->allowedFilters([
                AllowedFilter::partial('event'),
                AllowedFilter::partial('ip'),
                AllowedFilter::partial('description'),
                AllowedFilter::callback('search', function ($query, $value) {
                    $query->where(function ($q) use ($value) {
                        $q->where('description', 'like', "%{$value}%")
                            ->orWhere('event', 'like', "%{$value}%")
                            ->orWhere('ip', 'like', "%{$value}%")
                            ->orWhereHas('actor', function ($actorQuery) use ($value) {
                                $actorQuery->where('username', 'like', "%{$value}%")
                                    ->orWhere('email', 'like', "%{$value}%");
                            });
                    });
                }),
                AllowedFilter::callback('actor', function ($query, $value) {
                    $query->whereHas('actor', function ($actorQuery) use ($value) {
                        $actorQuery->where('uuid', $value);
                    });
                }),
            ])
            ->defaultSort('-timestamp')
            ->whereNotIn('activity_logs.event', ActivityLog::DISABLED_EVENTS)
            ->where(function (Builder $q) use ($server) {
                $q->whereJsonContains('properties->server->id', $server->id)
                  ->orWhereJsonContains('properties->server->uuid', $server->uuid);
            })
            ->where('activity_logs.is_admin', 0)
            ->when(config('activity.hide_admin_activity'), function (Builder $builder) use ($server) {
                $this->applyAdminActivityFilter($builder, $server);
            })
            ->paginate(min($request->query('per_page', 25), 100))
            ->appends($request->query());

        return $this->fractal->collection($activity)
            ->transformWith(ActivityLogTransformer::class)
            ->toArray();
    }

    public function users(ClientApiRequest $request, Server $server): array
    {
        $this->authorize(Permission::ACTION_ACTIVITY_READ, $server);

        $query = $server->activity()
            ->whereNotIn('activity_logs.event', ActivityLog::DISABLED_EVENTS)
            ->where(function (Builder $q) use ($server) {
                $q->whereJsonContains('properties->server->id', $server->id)
                  ->orWhereJsonContains('properties->server->uuid', $server->uuid);
            })
            ->where('activity_logs.is_admin', 0)
            ->whereNotNull('actor_id');

        if (config('activity.hide_admin_activity')) {
            $this->applyAdminActivityFilter($query, $server);
        }

        $users = $query
            ->join('users as u', 'activity_logs.actor_id', '=', 'u.id')
            ->select('u.uuid', 'u.username')
            ->groupBy('u.uuid', 'u.username')
            ->orderBy('u.username')
            ->get()
            ->map(function ($user) {
                return [
                    'uuid' => $user->uuid,
                    'username' => $user->username,
                ];
            });

        return ['data' => $users];
    }

    public function events(ClientApiRequest $request, Server $server): array
    {
        $this->authorize(Permission::ACTION_ACTIVITY_READ, $server);

        $query = $server->activity()
            ->whereNotIn('activity_logs.event', ActivityLog::DISABLED_EVENTS)
            ->where(function (Builder $q) use ($server) {
                $q->whereJsonContains('properties->server->id', $server->id)
                  ->orWhereJsonContains('properties->server->uuid', $server->uuid);
            })
            ->where('activity_logs.is_admin', 0);

        if (config('activity.hide_admin_activity')) {
            $this->applyAdminActivityFilter($query, $server);
        }

        $events = $query
            ->select('activity_logs.event')
            ->groupBy('activity_logs.event')
            ->get()
            ->pluck('event')
            ->sort()
            ->values();

        return ['data' => $events];
    }

    private function applyAdminActivityFilter(Builder $builder, Server $server): void
    {
        $subusers = $server->subusers()->pluck('user_id')->merge($server->owner_id);

        $builder->select('activity_logs.*')
            ->leftJoin('users', function (JoinClause $join) {
                $join->on('users.id', 'activity_logs.actor_id')
                    ->where('activity_logs.actor_type', (new User())->getMorphClass());
            })
            ->where(function (Builder $builder) use ($subusers) {
                $builder->whereNull('users.id')
                    ->orWhere('users.root_admin', 0)
                    ->orWhereIn('users.id', $subusers);
            });
    }
}
```
