<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\DisplayException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Everest\Services\Extensions\Manifest\ExtensionManifest;

/**
 * Verifies declared runtime dependencies before files or migrations are applied.
 */
class ExtensionRequirementService
{
    /** @var array<string, class-string> */
    private const PANEL_SERVICES = [
        'http-client' => HttpClientFactory::class,
        'queue' => QueueFactory::class,
        'schedule' => ExtensionScheduleService::class,
        'secrets' => ExtensionSecretStore::class,
        'hooks' => ExtensionHookDispatcher::class,
    ];

    public function __construct(private Container $container)
    {
    }

    /** @throws DisplayException */
    public function assertSatisfied(ExtensionManifest $manifest): void
    {
        $missing = [];

        foreach (array_unique((array) ($manifest->requirements['phpExtensions'] ?? [])) as $extension) {
            $extension = trim((string) $extension);
            if ($extension === '' || !extension_loaded($extension)) {
                $missing[] = sprintf('PHP extension "%s"', $extension === '' ? '(empty)' : $extension);
            }
        }

        foreach (array_unique((array) ($manifest->requirements['panelServices'] ?? [])) as $service) {
            $service = (string) $service;
            $abstract = self::PANEL_SERVICES[$service] ?? null;
            if ($abstract === null || !$this->canResolve($abstract)) {
                $missing[] = sprintf('panel service "%s"', $service);
            }
        }

        if ($missing !== []) {
            throw new DisplayException(sprintf('Extension "%s" cannot be installed because this panel is missing: %s.', $manifest->id, implode(', ', $missing)));
        }
    }

    private function canResolve(string $abstract): bool
    {
        try {
            $this->container->make($abstract);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
