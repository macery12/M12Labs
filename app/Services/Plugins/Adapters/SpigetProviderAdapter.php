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
        if (isset($project['data'])) {
            $project = $project['data'];
        }
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

        $versions = $files['data'] ?? $files ?? [];
        $version = collect($versions)->firstWhere('id', (int) $versionId) ?? ($versions[0] ?? []);
        if (isset($version['data'])) {
            $version = $version['data'];
        }

        $projectName = $project['name'] ?? ($project['title'] ?? null);
        if (!$projectName && isset($project['slug'])) {
            $projectName = $project['slug'];
        }
        $versionName = $version['displayName'] ?? $version['name'] ?? null;
        if (!$versionName && isset($version['fileName'])) {
            $versionName = pathinfo($version['fileName'], PATHINFO_FILENAME);
        }

        return [
            'url' => $this->spigetService->getDownloadUrl($projectId, $versionId)['url'],
            'fileName' => $version['fileName'] ?? 'plugin_' . $versionId . '.jar',
            'fileSize' => $version['fileLength'] ?? ($version['size'] ?? null),
            'projectName' => $projectName,
            'versionName' => $versionName,
        ];
    }
}
