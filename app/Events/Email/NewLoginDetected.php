<?php

namespace Everest\Events\Email;

use Everest\Events\Event;
use Everest\Models\User;
use Illuminate\Queue\SerializesModels;

class NewLoginDetected extends Event
{
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public User $user,
        public string $ipAddress,
        public string $userAgent,
        public string $correlationId,
        public \DateTimeInterface $loginAt,
        public ?string $location = null
    ) {
    }
}
