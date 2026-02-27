<?php

namespace Everest\Services\Plugins\Adapters;

use Everest\Services\Mods\SpigetService;
use Everest\Services\Plugins\ProviderAdapterInterface;
use Everest\Exceptions\Service\Mods\ModsServiceException;

class SpigetProviderAdapter implements ProviderAdapterInterface
{
    public function __construct(private SpigetService $spigetService)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function search(array $params = []): array
    {
        return $this->spigetService->searchMods($params);
    }

    /**
     * {@inheritdoc}
     */
    public function getProject(string|int $projectId): array
    {
        return $this->spigetService->getMod($projectId);
    }

    /**
     * {@inheritdoc}
     */
    public function listVersions(string|int $projectId, array $params = []): array
    {
        return $this->spigetService->getModFiles($projectId, $params);
    }

    /**
     * {@inheritdoc}
     */
    public function getDownloadUrl(string|int $projectId, string|int $versionId): array
    {
        $project = $this->spigetService->getMod($projectId);
        if ($project['isPremium'] ?? false) {
            throw new ModsServiceException('Premium resources cannot be downloaded via the panel.');
        }

        if ($project['isExternal'] ?? false) {
            throw new ModsServiceException('External Spigot resources must be downloaded manually.');
        }

        $files = $this->spigetService->getModFiles($projectId, [
            'index' => 0,
            'pageSize' => 10,
        ]);

        $matched = collect($files['data'] ?? [])->firstWhere('id', (int) $versionId) ?? ($files['data'][0] ?? []);

        return [
            'url' => $this->spigetService->getDownloadUrl($projectId, $versionId)['url'],
            'fileName' => $matched['fileName'] ?? 'plugin_' . $versionId . '.jar',
            'fileSize' => $matched['fileLength'] ?? null,
            'projectName' => $project['name'] ?? null,
            'versionName' => $matched['displayName'] ?? $matched['fileName'] ?? null,
        ];
    }
}
