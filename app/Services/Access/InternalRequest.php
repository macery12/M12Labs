<?php

namespace Everest\Services\Access;

/**
 * A request to run through the panel's own HTTP pipeline, as the acting user.
 *
 * Deliberately small. Everything a caller can say is here — method, path,
 * query, body, and an idempotency key — and everything it cannot say is
 * absent: no headers, no cookies, no authorization, no host. Those are
 * {@see InternalDispatch}'s to set, because each one of them is a security
 * property rather than a detail (see that class for which and why).
 *
 * The URI must be fully resolved before an instance exists. For the agent that
 * matters a great deal: the server identifier is interpolated from the turn's
 * bound context, never from model output, so a hallucinated id has nowhere to
 * land.
 */
final readonly class InternalRequest
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $query = [],
        public array $body = [],
        public ?string $idempotencyKey = null,
    ) {
    }

    public function isRead(): bool
    {
        return in_array(strtoupper($this->method), ['GET', 'HEAD'], true);
    }

    /**
     * The path plus query string, as it will be dispatched.
     */
    public function fullUri(): string
    {
        if ($this->query === []) {
            return $this->uri;
        }

        return $this->uri . '?' . http_build_query($this->query);
    }
}
