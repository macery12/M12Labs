<?php

namespace Everest\Events\Email;

use Everest\Events\Event;
use Everest\Models\Server;
use Everest\Models\User;
use Illuminate\Queue\SerializesModels;

class ServerUnsuspended extends Event
{
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Server $server,
        public User $user,
        public string $correlationId
    ) {
    }
}
