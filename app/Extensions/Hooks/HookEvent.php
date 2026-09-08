<?php

namespace Everest\Extensions\Hooks;

use Illuminate\Support\Str;

/**
 * A curated, versioned fact about something core did.
 *
 * Deliberately not the Eloquent observer events. Those fire inside the
 * transaction that caused them, carry a live mutable model, and — for deletion
 * — fire after the FK cascade has already run. A handler given one of those can
 * roll back a core transaction it knows nothing about, mutate a model core is
 * about to save, and read relations that no longer exist. Extensions get this
 * instead: a snapshot taken outside the transaction, carrying only scalars and
 * arrays, at a schema version handlers can branch on.
 *
 * The payload rule is absolute. A model in a payload is lazily loaded at the
 * handler's whim, and for a queued handler it is re-fetched at execution time
 * from a database that has moved on — which is exactly how a pre-delete handler
 * ends up reading nothing.
 */
abstract readonly class HookEvent
{
    public string $correlationId;

    public function __construct(?string $correlationId = null)
    {
        $this->correlationId = $correlationId ?? (string) Str::uuid();
    }

    /** The event name, matching ExtensionCapabilityVocabulary::HOOK_EVENTS. */
    abstract public function name(): string;

    /**
     * Incremented when the payload shape changes in a way a handler could
     * notice. Handlers receive it so an older package degrades rather than
     * breaking on a key that moved.
     */
    public function schemaVersion(): int
    {
        return 1;
    }

    /**
     * @return array<string, mixed> scalars and arrays only — never a model
     */
    abstract public function toPayload(): array;

    /**
     * The full envelope a handler receives.
     *
     * @return array<string, mixed>
     */
    final public function envelope(): array
    {
        return [
            'event' => $this->name(),
            'schemaVersion' => $this->schemaVersion(),
            'correlationId' => $this->correlationId,
            'payload' => $this->toPayload(),
        ];
    }
}
