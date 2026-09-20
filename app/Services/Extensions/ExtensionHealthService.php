<?php

namespace Everest\Services\Extensions;

use Everest\Models\ExtensionSecret;
use Everest\Models\ExtensionPackage;
use Everest\Models\ExtensionQueueJob;
use Illuminate\Support\Facades\Cache;
use Everest\Models\ExtensionHookHealth;
use Everest\Models\ExtensionPermission;

/**
 * What an extension's runtime state actually is, computed rather than stored.
 *
 * There is deliberately no extension_health table. Every input already exists
 * somewhere authoritative — the package row, the capability tables, the
 * migration log, the built asset manifest — and a summary table would be a
 * second copy that silently drifts from all of them. The one thing a cache
 * costs is 30 seconds of staleness on an admin page.
 *
 * The export is the other half: an operator asking for help needs to be able to
 * hand over what the panel thinks is wrong, which means it must be safe to
 * paste in public. Secret values are never read here; only key names and
 * whether something is configured.
 */
class ExtensionHealthService
{
    private const CACHE_TTL_SECONDS = 30;

    public function __construct(
        private ExtensionRuntimePlanService $plan,
        private ExtensionMigrationService $migrationService,
        private ExtensionSignatureService $signatureService,
        private ExtensionPackageIntegrityService $integrityService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forExtension(string $extensionId): array
    {
        return Cache::remember(
            'm12labs:extensions:health:' . $extensionId,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->compute($extensionId)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return ExtensionPackage::query()
            ->orderBy('extension_id')
            ->pluck('extension_id')
            ->map(fn (string $id): array => $this->forExtension($id))
            ->all();
    }

    public function flush(?string $extensionId = null): void
    {
        if ($extensionId !== null) {
            Cache::forget('m12labs:extensions:health:' . $extensionId);

            return;
        }

        foreach (ExtensionPackage::query()->pluck('extension_id') as $id) {
            Cache::forget('m12labs:extensions:health:' . $id);
        }
    }

    /**
     * A redacted report an operator can paste into a support thread.
     *
     * @return array<string, mixed>
     */
    public function export(string $extensionId): array
    {
        $health = $this->forExtension($extensionId);

        return [
            'generatedAt' => now()->toIso8601String(),
            'panelVersion' => config('app.version'),
            'extension' => $health,
            'notes' => [
                'Credential values are never included; only whether a key is configured.',
                'Job and hook errors are truncated class names and messages, not payloads.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(string $extensionId): array
    {
        $package = ExtensionPackage::query()->where('extension_id', $extensionId)->first();

        if ($package === null) {
            return ['id' => $extensionId, 'installed' => false];
        }

        $entry = $this->plan->entry($extensionId);
        // Runtime enforcement may have quarantined the package, or cleared an
        // integrity-only quarantine after its authentic bytes were restored.
        $package->refresh();
        $capabilities = $entry?->capabilities;

        return [
            'id' => $extensionId,
            'installed' => true,
            'version' => $package->installed_version,
            'previousVersion' => $package->previous_version,
            'state' => $package->state,
            'stateReason' => $package->state_reason,
            'manifestVersion' => $package->manifest_version,
            // The distinction that confuses operators most: a package can be
            // "enabled" and still not load, because loading also needs an
            // executable state, an intact capability projection and an
            // acceptable signature.
            'loadable' => $entry !== null,
            // Why not, in the panel's own terms. "state is enabled" was the
            // only thing the UI could say before, which is unhelpful precisely
            // when the state is fine and something else is stopping the load.
            'notLoadableReason' => $entry !== null ? null : $this->plan->exclusionReason($package),
            'signature' => [
                'state' => $package->signature_state,
                'keyId' => $package->signature_key_id,
                'verifiedAt' => $package->signature_verified_at?->toIso8601String(),
                'enforced' => $this->signatureService->signingRequired(),
            ],
            'capabilities' => $capabilities?->summary() ?? [],
            'integrity' => $this->integrity($package),
            'database' => $this->database($extensionId),
            'permissions' => $this->permissions($extensionId),
            'queues' => $this->queues($extensionId),
            'hooks' => $this->hooks($extensionId),
            'secrets' => $this->secrets($extensionId),
            'assets' => $this->assets($extensionId),
        ];
    }

    /**
     * Files still where they were installed, and a capability projection that
     * still matches its manifest. Both are why an extension can be enabled and
     * inert at the same time.
     *
     * @return array<string, mixed>
     */
    private function integrity(ExtensionPackage $package): array
    {
        $runtimeIntegrity = $this->integrityService->inspect($package);

        $projection = is_array($package->capabilities)
            ? $this->plan->hydrateCapabilities($package->capabilities)
            : null;
        $authenticatedCapabilities = $runtimeIntegrity->manifestAuthentic
            ? $runtimeIntegrity->manifest?->capabilities
            : null;

        return [
            'trackedFiles' => $runtimeIntegrity->trackedFiles(),
            'missingFiles' => $runtimeIntegrity->missingFiles,
            'modifiedFiles' => $runtimeIntegrity->modifiedFiles,
            'manifestAuthentic' => $runtimeIntegrity->manifestAuthentic,
            'runtimeVerified' => $runtimeIntegrity->valid,
            'failureReason' => $runtimeIntegrity->reason,
            'capabilityProjectionMatches' => is_string($package->capability_hash)
                && $package->capability_hash !== ''
                && $projection !== null
                && $authenticatedCapabilities !== null
                && hash_equals($authenticatedCapabilities->hash(), $package->capability_hash)
                && hash_equals($authenticatedCapabilities->hash(), $projection->hash()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function database(string $extensionId): array
    {
        return [
            'tablePrefix' => $this->migrationService->tablePrefix($extensionId),
            'tables' => $this->migrationService->listExtensionTables($extensionId),
            'ranMigrations' => $this->migrationService->ranMigrationNames($extensionId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function permissions(string $extensionId): array
    {
        $rows = ExtensionPermission::query()->where('extension_id', $extensionId)->get();

        return [
            'declared' => $rows->count(),
            'pendingApproval' => $rows->whereNull('approved_at')->count(),
            'suspended' => $rows->whereNotNull('suspended_at')->count(),
            'identifiers' => $rows->pluck('identifier')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queues(string $extensionId): array
    {
        $counts = ExtensionQueueJob::query()
            ->where('extension_id', $extensionId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $lastFailure = ExtensionQueueJob::query()
            ->where('extension_id', $extensionId)
            ->where('status', ExtensionQueueJob::STATUS_FAILED)
            ->latest('finished_at')
            ->first();

        return [
            'byStatus' => $counts,
            'lastFailure' => $lastFailure === null ? null : [
                'jobClass' => $lastFailure->job_class,
                'queue' => $lastFailure->queue_name,
                'at' => $lastFailure->finished_at?->toIso8601String(),
                // Already truncated to a class and message at write time.
                'error' => $lastFailure->last_error,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function hooks(string $extensionId): array
    {
        return ExtensionHookHealth::query()
            ->where('extension_id', $extensionId)
            ->get()
            ->map(fn (ExtensionHookHealth $health): array => [
                'event' => $health->event,
                'handler' => $health->handler,
                'invocations' => $health->invocations,
                'failures' => $health->failures,
                'consecutiveFailures' => $health->consecutive_failures,
                'averageMs' => $health->invocations > 0
                    ? (int) round($health->total_duration_ms / $health->invocations)
                    : 0,
                'breakerOpen' => $health->breaker_open_until !== null && $health->breaker_open_until->isFuture(),
                'quarantined' => $health->quarantined_at !== null,
                'lastError' => $health->last_error,
            ])
            ->all();
    }

    /**
     * Key names and whether each is set. Never a value, and never anything
     * derived from one — this ends up in a support thread.
     *
     * @return array<int, array<string, mixed>>
     */
    private function secrets(string $extensionId): array
    {
        return ExtensionSecret::query()
            ->where('extension_id', $extensionId)
            ->get()
            ->map(fn (ExtensionSecret $secret): array => [
                'key' => $secret->key,
                'configured' => true,
                'version' => $secret->key_version,
                'updatedAt' => $secret->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Whether this extension's frontend entry points survived the last build.
     *
     * A rebuild that silently dropped a page is otherwise invisible until
     * somebody clicks the nav item and gets a blank screen.
     *
     * @return array<string, mixed>
     */
    private function assets(string $extensionId): array
    {
        $manifestPath = public_path('build/manifest.json');

        if (!is_file($manifestPath)) {
            return ['built' => false, 'entries' => 0];
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['built' => false, 'entries' => 0];
        }

        $needle = sprintf('extensions/packages/%s/', $extensionId);
        $entries = 0;

        foreach (array_keys(is_array($manifest) ? $manifest : []) as $key) {
            if (str_contains((string) $key, $needle)) {
                ++$entries;
            }
        }

        return ['built' => true, 'entries' => $entries];
    }
}
