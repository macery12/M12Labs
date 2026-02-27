<?php

namespace Everest\Services\Plugins;

class ProviderAccessService
{
    public function __construct(private PluginProviderGate $providerGate)
    {
    }

    public function isProviderAllowed(string $providerKey, int $eggId, int $nestId): bool
    {
        return $this->providerGate->isProviderAllowed($providerKey, $nestId, $eggId);
    }

    public function getAllowedProvidersForServer(int $eggId, int $nestId): array
    {
        $providersByType = [
            'mods' => [
                ['key' => 'modrinth.mods', 'name' => 'modrinth'],
                ['key' => 'curseforge', 'name' => 'curseforge'],
            ],
            'modpacks' => [
                ['key' => 'curseforge', 'name' => 'curseforge'],
            ],
            'plugins' => [
                ['key' => 'spiget.plugins', 'name' => 'spiget'],
            ],
        ];

        $allowed = [
            'mods' => [],
            'modpacks' => [],
            'plugins' => [],
        ];

        foreach ($providersByType as $type => $providers) {
            foreach ($providers as $provider) {
                if ($this->isProviderAllowed($provider['key'], $eggId, $nestId)) {
                    $allowed[$type][] = $provider['name'];
                }
            }
        }

        return $allowed;
    }
}
