<?php

namespace Everest\Services\Extensions;

/**
 * The runtime plan as resolved for the current operation -- one HTTP request,
 * one queue job, one artisan command.
 *
 * The plan is a live database and filesystem read by design, and it used to be
 * rebuilt by every caller: five times on a bare 401 (route registration twice,
 * queue sizing twice, bindings once), each pass re-hashing every tracked
 * package file and re-verifying the signature -- ~28 ms of a 45 ms request.
 * Holding it for one operation keeps every property of the live read that
 * matters: nothing persists across operations, there is no manifest file or
 * cache entry, and a disable in another process is seen by the next request or
 * job.
 *
 * Bound `scoped()`, so the container drops it between queue jobs; the
 * ExtensionServiceProvider also clears it on JobProcessing, on any write to the
 * tables the plan reads, and on a rolled-back transaction, so an operation that
 * changes extension state sees the change on its next read.
 */
final class ExtensionRuntimeSnapshot
{
    /** Tables whose rows decide the plan. A write to any of them invalidates it. */
    public const SOURCE_TABLES = ['extension_packages', 'extension_configs', 'extension_trusted_keys'];

    /** @var array{plan: array<string, ExtensionRuntimeEntry>, core: array<int, string>}|null */
    private ?array $resolved = null;

    /**
     * $build returns null when the plan could not be read; that is never held.
     *
     * @param callable(): (array{plan: array<string, ExtensionRuntimeEntry>, core: array<int, string>}|null) $build
     *
     * @return array{plan: array<string, ExtensionRuntimeEntry>, core: array<int, string>}
     */
    public function remember(callable $build): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolved = $build();

        if ($resolved === null) {
            // A fresh install runs artisan before the tables exist. Failing
            // closed for this call is right; holding the failure is not.
            return ['plan' => [], 'core' => []];
        }

        return $this->resolved = $resolved;
    }

    public function held(): bool
    {
        return $this->resolved !== null;
    }

    public function forget(): void
    {
        $this->resolved = null;
    }

    /**
     * Whether a statement could change the plan. Deliberately coarse: a false
     * positive costs one rebuild, a false negative serves a stale plan.
     */
    public static function invalidatedBy(string $sql): bool
    {
        if (preg_match('/^\s*(insert|update|delete|replace|truncate|drop|alter)\b/i', $sql) !== 1) {
            return false;
        }

        foreach (self::SOURCE_TABLES as $table) {
            if (stripos($sql, $table) !== false) {
                return true;
            }
        }

        return false;
    }
}
