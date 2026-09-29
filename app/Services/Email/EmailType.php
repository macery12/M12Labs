<?php

namespace Everest\Services\Email;

use Everest\Mail\PanelMail;

final class EmailType
{
    /**
     * @param class-string<PanelMail> $mailable
     * @param class-string $event
     */
    public function __construct(
        public readonly string $key,
        public readonly string $mailable,
        public readonly string $event,
        public readonly string $category,
        public readonly string $name,
        public readonly string $description,
        public readonly bool $locked,
    ) {
    }

    public function view(): string
    {
        return PanelMail::viewFor($this->key);
    }

    /**
     * Build this type's Mailable from its event. Each Mailable's fromEvent()
     * takes its own event class, so it cannot be declared on the base class.
     */
    public function mailFor(object $event): PanelMail
    {
        $mail = call_user_func([$this->mailable, 'fromEvent'], $event);

        if (!$mail instanceof PanelMail) {
            throw new \LogicException("{$this->mailable}::fromEvent() must return the Mailable.");
        }

        return $mail;
    }
}
