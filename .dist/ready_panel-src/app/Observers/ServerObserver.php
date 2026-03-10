<?php

namespace Everest\Observers;

use Everest\Events;
use Everest\Models\Server;
use Everest\Jobs\CustomDomains\CleanupServerCustomDomainsJob;
use Illuminate\Foundation\Bus\DispatchesJobs;

class ServerObserver
{
    use DispatchesJobs;

    /**
     * Listen to the Server creating event.
     */
    public function creating(Server $server): void
    {
        event(new Events\Server\Creating($server));
    }

    /**
     * Listen to the Server created event.
     */
    public function created(Server $server): void
    {
        event(new Events\Server\Created($server));

        // Dispatch email notification event
        if ($server->user) {
            event(new Events\Email\ServerCreatedEmail(
                server: $server,
                user: $server->user,
                correlationId: \Illuminate\Support\Str::uuid()->toString()
            ));
        }
    }

    /**
     * Listen to the Server deleting event.
     */
    public function deleting(Server $server): void
    {
        event(new Events\Server\Deleting($server));
    }

    /**
     * Listen to the Server deleted event.
     */
    public function deleted(Server $server): void
    {
        event(new Events\Server\Deleted($server));

        if (config('modules.custom_domains.cleanup_on_delete', true)) {
            CleanupServerCustomDomainsJob::dispatch($server->id);
        }
    }

    /**
     * Listen to the Server saving event.
     */
    public function saving(Server $server): void
    {
        event(new Events\Server\Saving($server));
    }

    /**
     * Listen to the Server saved event.
     */
    public function saved(Server $server): void
    {
        event(new Events\Server\Saved($server));
    }

    /**
     * Listen to the Server updating event.
     */
    public function updating(Server $server): void
    {
        event(new Events\Server\Updating($server));
    }

    /**
     * Listen to the Server saved event.
     */
    public function updated(Server $server): void
    {
        event(new Events\Server\Updated($server));
    }
}
