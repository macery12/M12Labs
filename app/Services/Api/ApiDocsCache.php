<?php

namespace Everest\Services\Api;

use Carbon\CarbonImmutable;
use Everest\Jobs\Api\GenerateApiDocsJob;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * Hands the generated OpenAPI document from the queue worker to the request.
 *
 * Generation walks every route through Scramble and peaks around 270 MB, over
 * the 128 MB php-fpm limit, so /api/openapi.json 500'd whenever the cache was
 * cold. It now runs in GenerateApiDocsJob and the request only ever reads the
 * cache: the spec when there is one, a pending marker while the job runs, or
 * the failure the job left behind.
 *
 * An expired spec keeps being served while a fresh one generates, so the page
 * only waits on a truly cold cache or an explicit Regenerate.
 */
class ApiDocsCache
{
    public const SPEC_KEY = 'api_docs.openapi';
    public const PENDING_KEY = 'api_docs.openapi.pending';
    public const FAILED_KEY = 'api_docs.openapi.failed';

    // How long a queued run counts as in flight. If a worker never picks the
    // job up, the next read after this queues it again.
    public const PENDING_TTL = 300;

    public function __construct(private CacheFactory $cache)
    {
    }

    /**
     * @return array{spec: array<string, mixed>, generated_at: int}|null
     */
    public function current(): ?array
    {
        $entry = $this->store()->get(self::SPEC_KEY);

        // A pre-queue deploy cached the bare spec under this key. Treat it as
        // absent rather than serving something without a timestamp.
        return is_array($entry) && isset($entry['spec'], $entry['generated_at']) ? $entry : null;
    }

    /**
     * @param array{generated_at: int} $entry
     */
    public function isStale(array $entry): bool
    {
        $ttl = (int) config('api-docs.cache.ttl', 3600);

        return CarbonImmutable::now()->getTimestamp() - $entry['generated_at'] >= $ttl;
    }

    /**
     * Seconds since the run in flight was queued, or null when none is.
     */
    public function pendingFor(): ?int
    {
        $queuedAt = $this->store()->get(self::PENDING_KEY);

        return is_int($queuedAt) ? max(0, CarbonImmutable::now()->getTimestamp() - $queuedAt) : null;
    }

    public function failure(): ?string
    {
        $message = $this->store()->get(self::FAILED_KEY);

        return is_string($message) ? $message : null;
    }

    /**
     * Queue a generation run unless one is already in flight.
     */
    public function queue(): void
    {
        if (!$this->store()->add(self::PENDING_KEY, CarbonImmutable::now()->getTimestamp(), self::PENDING_TTL)) {
            return;
        }

        $this->store()->forget(self::FAILED_KEY);
        GenerateApiDocsJob::dispatch();
    }

    /**
     * Drop the spec and any recorded failure, so the next read regenerates.
     */
    public function forget(): void
    {
        $this->store()->forget(self::SPEC_KEY);
        $this->store()->forget(self::FAILED_KEY);
    }

    /**
     * @param array<string, mixed> $spec
     */
    public function put(array $spec): void
    {
        // No TTL: expiry is judged by isStale() so an old spec can still be
        // served while the next one generates.
        $this->store()->forever(self::SPEC_KEY, [
            'spec' => $spec,
            'generated_at' => CarbonImmutable::now()->getTimestamp(),
        ]);
        $this->store()->forget(self::PENDING_KEY);
    }

    public function fail(string $message): void
    {
        // Kept for an hour: long enough for the page to show it, and a failure
        // is never retried by a plain read, only by Regenerate.
        $this->store()->put(self::FAILED_KEY, $message, 3600);
        $this->store()->forget(self::PENDING_KEY);
    }

    private function store(): Repository
    {
        return $this->cache->store(config('api-docs.cache.store'));
    }
}
