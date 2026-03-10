<?php

namespace Everest\Events\Email;

use Everest\Events\Event;
use Everest\Models\User;
use Illuminate\Queue\SerializesModels;

class AccountCreated extends Event
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
