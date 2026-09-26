<?php

namespace Everest\Jobs\Api;

use Everest\Jobs\Job;
use Dedoc\Scramble\Generator;
use Everest\Services\Api\ApiDocsCache;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Builds the OpenAPI document off the request path. See ApiDocsCache for why.
 *
 * Only ApiDocsCache::queue() dispatches this, and its pending marker is what
 * keeps a second run from being queued while one is in flight.
 *
 * One try: a generation failure is deterministic (a route Scramble cannot
 * analyse), so retrying only delays the error the admin needs to see.
 */
#[Timeout(120)]
#[Tries(1)]
class GenerateApiDocsJob extends Job implements ShouldQueue
{
    use Dispatchable;

    public function handle(Generator $generator, ApiDocsCache $docs): void
    {
        $docs->put($generator());
    }

    public function failed(?\Throwable $exception): void
    {
        app(ApiDocsCache::class)->fail(
            $exception?->getMessage() ?: 'The API reference could not be generated.'
        );
    }
}
