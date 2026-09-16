<?php

namespace Everest\Exceptions\Service\Streaming;

use Illuminate\Http\Response;
use Everest\Exceptions\DisplayException;

/**
 * No worker may be held for this stream right now.
 *
 * A refusal rather than a queue. The queueing in front of AI inference exists
 * because a GPU serves a fixed number of requests and thrashing it is worse
 * than waiting; a stream has no such scarce resource behind it, so a queue here
 * would be a mechanism protecting nothing while holding a worker to do it.
 *
 * Carries `Retry-After` so a client backs off on the server's terms instead of
 * inventing an interval, which is the difference between a deployment
 * recovering from a crowd and being kept saturated by one.
 */
class StreamUnavailableException extends DisplayException
{
    public function __construct(string $message, private readonly int $retryAfterSeconds = 15)
    {
        parent::__construct($message, null, self::LEVEL_INFO);
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_SERVICE_UNAVAILABLE;
    }

    public function getHeaders(): array
    {
        return ['Retry-After' => (string) $this->retryAfterSeconds];
    }
}
