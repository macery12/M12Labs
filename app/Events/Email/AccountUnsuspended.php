<?php

namespace Everest\Events\Email;

use Everest\Events\Event;
use Everest\Models\User;
use Illuminate\Queue\SerializesModels;

class AccountUnsuspended extends Event
{
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public User $user,
        public string $correlationId
    ) {
    }
}
