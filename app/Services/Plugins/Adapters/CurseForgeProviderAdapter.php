<?php

namespace Everest\Services\Plugins\Adapters;

use Everest\Services\Mods\CurseForgeService;
use Everest\Services\Plugins\ProviderAdapterInterface;
use Everest\Exceptions\Service\Mods\ModsServiceException;

class CurseForgeProviderAdapter implements ProviderAdapterInterface
{
    public function __construct(private CurseForgeService $curseForgeService)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function search(array $params = []): array
    {
        return $this->curseForgeService->searchMods($params);
    }

    /**
     * {@inheritdoc}
     */
    public function getProject(string|int $projectId): array
    {
        return $this->curseForgeService->getMod((int) $projectId);
    }

    /**
     * {@inheritdoc}
     */
    public function listVersions(string|int $projectId, array $params = []): array
    {
        return $this->curseForgeService->getModFiles((int) $projectId, $params);
    }

    /**
     * {@inheritdoc}
     */
    public function getDownloadUrl(string|int $projectId, string|int $versionId): array
    {
        $file = $this->curseForgeService->getModFile((int) $projectId, (int) $versionId);
        $fileData = $file['data'] ?? [];
        $project = $this->curseForgeService->getMod((int) $projectId)['data'] ?? [];

        return [
            'url' => $this->curseForgeService->getModFileDownloadUrl((int) $projectId, (int) $versionId),
            'fileName' => $fileData['fileName'] ?? null,
            'fileSize' => $fileData['fileLength'] ?? null,
            'projectName' => $project['name'] ?? null,
            'versionName' => $fileData['displayName'] ?? null,
        ];
    }
}
