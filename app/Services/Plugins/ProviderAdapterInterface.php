<?php

namespace Everest\Services\Plugins;

use Everest\Exceptions\Service\Mods\ModsServiceException;

interface ProviderAdapterInterface
{
    /**
     * Search for projects on the provider.
     *
     * @throws ModsServiceException
     */
    public function search(array $params = []): array;

    /**
     * Get a single project by ID.
     *
     * @throws ModsServiceException
     */
    public function getProject(string|int $projectId): array;

    /**
     * List versions for a project.
     *
     * @throws ModsServiceException
     */
    public function listVersions(string|int $projectId, array $params = []): array;

    /**
     * Get download details for a project/version.
     *
     * @return array{url: string, fileName: string|null, fileSize: int|null}
     *
     * @throws ModsServiceException
     */
    public function getDownloadUrl(string|int $projectId, string|int $versionId): array;
}
