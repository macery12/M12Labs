<?php

namespace Everest\Extensions\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Everest\Models\ExtensionQueueJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Everest\Services\Queue\QueueTopology;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Everest\Services\Extensions\ExtensionQueueRegistry;
use Everest\Extensions\Jobs\Middleware\ExtensionEnabledGate;
use Everest\Extensions\Jobs\Middleware\ExtensionConcurrencyLimit;

/**
 * The base class every extension job extends. Part of the SDK surface, so its
 * shape is a contract package authors depend on.
 *
 * A package supplies a queue group name and a handle(); everything that decides
 * how the job is treated by the queue comes from the panel:
 *
 *  - The **queue** is pinned here rather than routed through config/queue.php,
 *    which is the one deliberate exception to that file's rule. Package job
 *    classes are not knowable in advance and must not become routable by
 *    editing core config.
 *  - The **extension id** is derived from the class namespace, so a job cannot
 *    claim to belong to a different extension and inherit its quota, limiter or
 *    enabled state.
 *  - **tries, timeout, backoff and uniqueness** come from the verified
 *    manifest. A job that could set its own would be able to pin a worker for
 *    as long as it liked and retry a failing external call forever.
 *
 * The middleware stack fails closed: a disabled extension, an undeclared queue
 * group or a drain in progress all stop the job before handle() runs.
 */
abstract class ExtensionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Namespace segment that identifies a package's own classes. */
    private const PACKAGE_NAMESPACE = 'Everest\\Extensions\\Packages\\';

    /** Where core's own extension jobs live. */
    private const CORE_NAMESPACE = 'Everest\\Extensions\\Jobs\\';

    public function __construct()
    {
        $this->onQueue(app(QueueTopology::class)->queueFor('extensions'));
        $this->onConnection(app(QueueTopology::class)->connectionFor('extensions'));
    }

    /**
     * The logical queue group this job belongs to, which must be one the
     * manifest declares under capabilities.queues.
     */
    abstract public function queueGroup(): string;

    /**
     * The owning extension, taken from the class namespace rather than from a
     * property a package controls.
     */
    final public function extensionId(): string
    {
        $class = static::class;

        // A package's own job: ownership is the namespace, full stop. Final and
        // unreachable by an override, so a package cannot claim another
        // extension's quota, limiter or enabled state.
        if (str_starts_with($class, self::PACKAGE_NAMESPACE)) {
            return explode('\\', substr($class, strlen(self::PACKAGE_NAMESPACE)))[0];
        }

        // One of core's own jobs, running work on behalf of an extension named
        // at construction — RunExtensionHookJob is the case that exists.
        if (str_starts_with($class, self::CORE_NAMESPACE)) {
            return $this->ownerExtensionId();
        }

        throw new \LogicException(sprintf('%s extends ExtensionJob but lives under neither %s<id>\\ nor %s.', $class, self::PACKAGE_NAMESPACE, self::CORE_NAMESPACE));
    }

    /**
     * The extension a core-owned job is acting for. Overriding this in a
     * package class has no effect: extensionId() never consults it for a class
     * under the package namespace.
     */
    protected function ownerExtensionId(): string
    {
        throw new \LogicException(static::class . ' must say which extension it runs on behalf of.');
    }

    /**
     * Horizon tags. An operator filtering by extension:<id> sees exactly this
     * package's work, including across a version upgrade.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        $id = $this->extensionId();

        return array_values(array_filter([
            'extension:' . $id,
            'ext-queue:' . $id . ':' . $this->queueGroup(),
            ($version = app(\Everest\Services\Extensions\ExtensionRuntimePlanService::class)->entry($id)?->version)
                ? 'extension-version:' . $version
                : null,
        ]));
    }

    /**
     * A job with nothing declared behind it gets one attempt and the shortest
     * budget, not the generous defaults it asked for: the enabled gate is about
     * to discard it, and a package that has lost its declaration should not
     * still get five retries out of the panel on the way down.
     */
    public function tries(): int
    {
        $definition = $this->definition();

        return $definition === null ? 1 : $definition->maxAttempts;
    }

    public function timeout(): int
    {
        $definition = $this->definition();

        return $definition === null ? 60 : $definition->timeoutSeconds;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $definition = $this->definition();

        return $definition === null ? [10] : $definition->backoffSeconds;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $definition = $this->definition();
        $middleware = [new ExtensionEnabledGate($this->extensionId(), $this->queueGroup())];

        if ($definition === null) {
            return $middleware;
        }

        if ($definition->parsedRateLimit() !== null) {
            $middleware[] = new RateLimited($definition->limiterName($this->extensionId()));
        }

        if ($definition->maxConcurrent !== null) {
            $middleware[] = new ExtensionConcurrencyLimit(
                $definition->limiterName($this->extensionId()),
                $definition->maxConcurrent,
                $definition->timeoutSeconds,
            );
        }

        if ($definition->uniqueForSeconds !== null) {
            $middleware[] = (new WithoutOverlapping($this->overlapKey()))
                ->expireAfter($definition->uniqueForSeconds)
                ->releaseAfter($definition->uniqueForSeconds);
        }

        return $middleware;
    }

    /**
     * Overlap key for uniqueForSeconds. Defaults to the job class, which
     * serializes a group of identical jobs; a package narrows it by overriding
     * (for example, per server).
     */
    public function overlapKey(): string
    {
        return static::class;
    }

    /**
     * Record the terminal failure. The message is kept, the payload is not:
     * a job's constructor arguments can carry an extension's secrets, and this
     * row is shown on an admin page.
     */
    public function failed(?\Throwable $exception): void
    {
        $summary = $exception === null
            ? 'Job failed without an exception.'
            : sprintf('%s: %s', $exception::class, \Illuminate\Support\Str::limit($exception->getMessage(), 500));

        try {
            ExtensionQueueJob::query()
                ->where('job_uuid', $this->job?->uuid())
                ->update([
                    'status' => ExtensionQueueJob::STATUS_FAILED,
                    'last_error' => $summary,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $recordingFailure) {
            Log::warning('Could not record an extension job failure.', [
                'extension' => $this->extensionId(),
                'exception' => $recordingFailure->getMessage(),
            ]);
        }
    }

    private function definition(): ?\Everest\Services\Extensions\Manifest\Definitions\QueueDefinition
    {
        return app(ExtensionQueueRegistry::class)->definition($this->extensionId(), $this->queueGroup());
    }
}
