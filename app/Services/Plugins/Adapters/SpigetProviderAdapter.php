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
        $files = $this->spigetService->getModFiles($projectId, [
            'index' => 0,
            'pageSize' => 100,
        ]);

        $matched = [];
        foreach ($files['data'] ?? [] as $file) {
            if ((int) ($file['id'] ?? 0) === (int) $versionId) {
                $matched = $file;
                break;
            }
        }

        return [
            'url' => $matched['downloadUrl'] ?? $this->spigetService->getDownloadUrl($projectId, $versionId)['url'],
            'fileName' => $matched['fileName'] ?? 'plugin_' . $versionId . '.jar',
            'fileSize' => $matched['fileLength'] ?? null,
        ];
    }
}
