<?php

namespace Everest\Services\Mods;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Everest\Exceptions\Service\Mods\ModsServiceException;

class SpigetService
{
    private string $endpoint;
    private int $defaultPageSize;
    private array $cacheTtl = [
        'search' => 300,
        'project' => 600,
        'versions' => 300,
    ];

    public function __construct()
    {
        $this->endpoint = rtrim(config('modules.mods.spiget_api_url', 'https://api.spiget.org/v2'), '/');
        $this->defaultPageSize = (int) config('modules.mods.default_page_size', 20);
    }

    /**
     * Search plugins on Spiget.
     *
     * @throws ModsServiceException
     */
    public function searchMods(array $params = []): array
    {
        $query = $params['searchFilter'] ?? '';
        $pageSize = (int) ($params['pageSize'] ?? $this->defaultPageSize);
        $index = (int) ($params['index'] ?? 0);
        $page = $pageSize > 0 ? (int) floor($index / $pageSize) : 0;

        $cacheKey = sprintf('spiget_search_%s_%s_%s', md5($query), $page, $pageSize);

        return $this->makeCachedRequest($cacheKey, $this->cacheTtl['search'], function () use ($query, $page, $pageSize) {
            $path = $query ? '/search/resources/' . urlencode($query) : '/resources';
            $response = $this->request('GET', $path, [
                'page' => $page,
                'size' => $pageSize,
                'sort' => '-downloads',
                'fields' => 'id,name,tag,downloads,rating,releaseDate,updateDate,testedVersions,version,author,icon',
            ]);

            $authors = $this->hydrateAuthors($response);

            $data = array_map(function ($item) use ($authors) {
                return $this->transformResourceToCommonFormat($item, $authors);
            }, $response);

            $resultCount = count($data);
            $totalCount = $resultCount + ($page * $pageSize);

            return [
                'data' => $data,
                'pagination' => [
                    'index' => $page * $pageSize,
                    'pageSize' => $pageSize,
                    'resultCount' => $resultCount,
                    'totalCount' => $totalCount,
                ],
            ];
        });
    }

    /**
     * Get a plugin by ID.
     *
     * @throws ModsServiceException
     */
    public function getMod(string|int $modId): array
    {
        $cacheKey = "spiget_mod_{$modId}";

        return $this->makeCachedRequest($cacheKey, $this->cacheTtl['project'], function () use ($modId) {
            $resource = $this->request('GET', "/resources/{$modId}", [
                'fields' => 'id,name,tag,downloads,rating,releaseDate,updateDate,testedVersions,version,author,icon',
            ]);

            $authors = $this->hydrateAuthors([$resource]);

            return $this->transformResourceToCommonFormat($resource, $authors);
        });
    }

    /**
     * Get versions for a plugin.
     *
     * @throws ModsServiceException
     */
    public function getModFiles(string|int $modId, array $params = []): array
    {
        $pageSize = (int) ($params['pageSize'] ?? 20);
        $index = (int) ($params['index'] ?? 0);
        $page = $pageSize > 0 ? (int) floor($index / $pageSize) : 0;

        $cacheKey = sprintf('spiget_versions_%s_%s_%s', $modId, $page, $pageSize);

        return $this->makeCachedRequest($cacheKey, $this->cacheTtl['versions'], function () use ($modId, $page, $pageSize) {
            $versions = $this->request('GET', "/resources/{$modId}/versions", [
                'page' => $page,
                'size' => $pageSize,
                'sort' => '-releaseDate',
            ]);

            $files = array_map(function ($version) use ($modId) {
                return $this->transformVersionToFile($modId, $version);
            }, $versions);

            $resultCount = count($files);
            $totalCount = $resultCount + ($page * $pageSize);

            return [
                'data' => $files,
                'pagination' => [
                    'index' => $page * $pageSize,
                    'pageSize' => $pageSize,
                    'resultCount' => $resultCount,
                    'totalCount' => $totalCount,
                ],
            ];
        });
    }

    /**
     * Get download URL for a version.
     *
     * @throws ModsServiceException
     */
    public function getDownloadUrl(string|int $modId, string|int $versionId): string
    {
        return $this->endpoint . "/resources/{$modId}/versions/{$versionId}/download";
    }

    /**
     * Spiget does not expose Minecraft version metadata in a consistent way; return tested versions when available.
     */
    public function getMinecraftVersions(): array
    {
        return ['data' => []];
    }

    /**
     * Spiget does not expose mod loader metadata; return empty array for compatibility.
     */
    public function getModLoaderTypes(): array
    {
        return ['data' => []];
    }

    /**
     * Basic rate limit placeholder for analytics compatibility.
     */
    public function getRateLimitUsage(): array
    {
        return [
            'requests_this_minute' => 0,
            'requests_this_hour' => 0,
            'limit_per_minute' => 120,
            'limit_per_hour' => 3000,
        ];
    }

    /**
     * Transform a Spiget resource into the common mod representation used by the UI.
     */
    private function transformResourceToCommonFormat(array $resource, array $authors): array
    {
        $authorId = $resource['author']['id'] ?? null;
        $authorName = $authorId && isset($authors[$authorId]) ? $authors[$authorId] : 'Unknown';
        $testedVersions = $resource['testedVersions'] ?? [];
        $latestVersionName = $resource['version']['id'] ?? null;

        return [
            'id' => $resource['id'],
            'gameId' => 432,
            'name' => $resource['name'] ?? 'Unknown Plugin',
            'slug' => (string) ($resource['id'] ?? ''),
            'links' => [
                'websiteUrl' => "https://www.spigotmc.org/resources/{$resource['id']}",
                'wikiUrl' => '',
                'issuesUrl' => '',
                'sourceUrl' => '',
            ],
            'summary' => $resource['tag'] ?? 'No description provided.',
            'status' => 4,
            'downloadCount' => $resource['downloads'] ?? 0,
            'isFeatured' => false,
            'primaryCategoryId' => 0,
            'categories' => [],
            'classId' => 0,
            'authors' => [
                [
                    'id' => $authorId ?? 0,
                    'name' => $authorName,
                    'url' => $authorId ? "https://api.spiget.org/v2/authors/{$authorId}" : '',
                ],
            ],
            'logo' => [
                'id' => $resource['icon']['id'] ?? 0,
                'modId' => $resource['id'] ?? 0,
                'title' => $resource['name'] ?? '',
                'description' => '',
                'thumbnailUrl' => $this->endpoint . '/resources/' . $resource['id'] . '/icon',
                'url' => $this->endpoint . '/resources/' . $resource['id'] . '/icon',
            ],
            'screenshots' => [],
            'mainFileId' => $latestVersionName ?? 0,
            'latestFiles' => $latestVersionName ? [
                [
                    'id' => $latestVersionName,
                    'fileName' => $latestVersionName . '.jar',
                    'displayName' => $latestVersionName,
                    'gameVersions' => $testedVersions,
                    'fileDate' => isset($resource['updateDate']) ? date('c', (int) $resource['updateDate']) : '',
                    'fileLength' => 0,
                    'releaseType' => 1,
                    'downloadUrl' => null,
                    'downloadCount' => $resource['downloads'] ?? 0,
                    'modules' => [],
                    'dependencies' => [],
                    'isAvailable' => true,
                    'fileStatus' => 4,
                    'sortableGameVersions' => [],
                    'alternateFileId' => 0,
                    'isServerPack' => false,
                    'fileFingerprint' => 0,
                ],
            ] : [],
            'latestFilesIndexes' => [],
            'dateCreated' => isset($resource['releaseDate']) ? date('c', (int) $resource['releaseDate']) : '',
            'dateModified' => isset($resource['updateDate']) ? date('c', (int) $resource['updateDate']) : '',
            'dateReleased' => isset($resource['releaseDate']) ? date('c', (int) $resource['releaseDate']) : '',
            'allowModDistribution' => true,
            'gamePopularityRank' => 0,
        ];
    }

    private function transformVersionToFile(string|int $modId, array $version): array
    {
        $fileName = ($version['name'] ?? 'plugin') . '.jar';

        return [
            'id' => $version['id'],
            'gameId' => 432,
            'modId' => (int) $modId,
            'isAvailable' => true,
            'displayName' => $version['name'] ?? 'Unknown',
            'fileName' => $fileName,
            'releaseType' => 1,
            'fileStatus' => 4,
            'hashes' => [],
            'fileDate' => isset($version['releaseDate']) ? date('c', (int) $version['releaseDate']) : '',
            'fileLength' => $version['size'] ?? 0,
            'downloadCount' => $version['downloads'] ?? 0,
            'downloadUrl' => $this->getDownloadUrl($modId, $version['id']),
            'gameVersions' => [],
            'sortableGameVersions' => [],
            'dependencies' => [],
            'alternateFileId' => 0,
            'isServerPack' => false,
            'fileFingerprint' => 0,
            'modules' => [],
        ];
    }

    /**
     * Fetch and cache author names for a list of resources.
     *
     * @throws ModsServiceException
     */
    private function hydrateAuthors(array $resources): array
    {
        $authorIds = array_unique(array_filter(array_map(function ($resource) {
            return $resource['author']['id'] ?? null;
        }, $resources)));

        $authors = [];
        foreach ($authorIds as $authorId) {
            $cacheKey = "spiget_author_{$authorId}";
            $authors[$authorId] = Cache::remember($cacheKey, 3600, function () use ($authorId) {
                $author = $this->request('GET', "/authors/{$authorId}", [
                    'fields' => 'id,name',
                ]);

                return $author['name'] ?? 'Unknown';
            });
        }

        return $authors;
    }

    /**
     * Make a cached request wrapper.
     *
     * @throws ModsServiceException
     */
    private function makeCachedRequest(string $cacheKey, int $ttl, callable $callback)
    {
        return Cache::remember($cacheKey, $ttl, $callback);
    }

    /**
     * Perform an HTTP request to the Spiget API.
     *
     * @throws ModsServiceException
     */
    private function request(string $method, string $path, array $params = []): array
    {
        $url = $this->endpoint . $path;

        $response = Http::timeout(15)->get($url, $params);

        if (!$response->successful()) {
            throw new ModsServiceException('Failed to communicate with Spiget API.');
        }

        $decoded = $response->json();
        if (!is_array($decoded)) {
            throw new ModsServiceException('Invalid response from Spiget API.');
        }

        return $decoded;
    }
}
