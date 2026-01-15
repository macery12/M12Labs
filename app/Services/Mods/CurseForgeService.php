<?php

namespace Everest\Services\Mods;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Everest\Exceptions\Service\Mods\ModsServiceException;

class CurseForgeService
{
    private Client $client;
    private string $apiKey;
    private string $endpoint;
    private int $requestsPerMinute;
    private int $requestsPerHour;

    /**
     * CurseForgeService constructor.
     */
    public function __construct()
    {
        $this->apiKey = config('modules.mods.curseforge_api_key') ?: '';
        $this->endpoint = config('modules.mods.curseforge_api_url') ?: 'https://api.curseforge.com/v1';
        $this->requestsPerMinute = config('modules.mods.rate_limit.requests_per_minute', 30);
        $this->requestsPerHour = config('modules.mods.rate_limit.requests_per_hour', 1800);

        $this->client = new Client([
            'base_uri' => rtrim($this->endpoint, '/') . '/',
            'timeout' => 30,
        ]);
    }

    /**
     * Check if rate limit is exceeded.
     *
     * @return bool
     */
    private function checkRateLimit(): bool
    {
        $minuteKey = 'curseforge_rate_limit_minute';
        $hourKey = 'curseforge_rate_limit_hour';

        $minuteCount = Cache::get($minuteKey, 0);
        $hourCount = Cache::get($hourKey, 0);

        if ($minuteCount >= $this->requestsPerMinute) {
            throw new ModsServiceException('CurseForge API rate limit exceeded (per minute). Please try again later.');
        }

        if ($hourCount >= $this->requestsPerHour) {
            throw new ModsServiceException('CurseForge API rate limit exceeded (per hour). Please try again later.');
        }

        // Increment counters
        Cache::put($minuteKey, $minuteCount + 1, 60);
        Cache::put($hourKey, $hourCount + 1, 3600);

        return true;
    }

    /**
     * Get current rate limit usage.
     *
     * @return array
     */
    public function getRateLimitUsage(): array
    {
        $minuteKey = 'curseforge_rate_limit_minute';
        $hourKey = 'curseforge_rate_limit_hour';

        return [
            'requests_this_minute' => Cache::get($minuteKey, 0),
            'requests_this_hour' => Cache::get($hourKey, 0),
            'limit_per_minute' => $this->requestsPerMinute,
            'limit_per_hour' => $this->requestsPerHour,
        ];
    }

    /**
     * Make a request to the CurseForge API.
     *
     * @throws ModsServiceException
     */
    private function makeRequest(string $method, string $path, array $params = []): array
    {
        if (empty($this->apiKey)) {
            throw new ModsServiceException('CurseForge API key is not configured.');
        }

        $this->checkRateLimit();

        try {
            $options = [
                'headers' => [
                    'Accept' => 'application/json',
                    'x-api-key' => $this->apiKey,
                ],
            ];

            if (!empty($params)) {
                if ($method === 'GET') {
                    $options['query'] = $params;
                } else {
                    $options['json'] = $params;
                }
            }

            $response = $this->client->request($method, $path, $options);
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('CurseForge API JSON decode error: ' . json_last_error_msg());
                throw new ModsServiceException('Failed to decode CurseForge API response.');
            }

            return $data;
        } catch (GuzzleException $e) {
            Log::error('CurseForge API request failed: ' . $e->getMessage());
            
            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                if ($statusCode === 429) {
                    throw new ModsServiceException('CurseForge API rate limit exceeded. Please try again later.');
                } elseif ($statusCode === 401 || $statusCode === 403) {
                    throw new ModsServiceException('Invalid CurseForge API key.');
                }
            }
            
            throw new ModsServiceException('Failed to connect to CurseForge API: ' . $e->getMessage());
        }
    }

    /**
     * Search for mods in the CurseForge database.
     *
     * @param array $params Search parameters
     * @return array
     * @throws ModsServiceException
     */
    public function searchMods(array $params = []): array
    {
        // CurseForge Minecraft game ID is 432
        $defaultParams = [
            'gameId' => 432,
            'classId' => 6, // Mods class
            'pageSize' => min($params['pageSize'] ?? 20, config('modules.mods.max_page_size', 50)),
        ];

        $searchParams = array_merge($defaultParams, array_filter([
            'searchFilter' => $params['searchFilter'] ?? null,
            'sortField' => $params['sortField'] ?? null,
            'sortOrder' => $params['sortOrder'] ?? null,
            'gameVersion' => $params['gameVersion'] ?? null,
            'modLoaderType' => $params['modLoaderType'] ?? null,
            'index' => $params['index'] ?? 0,
        ], function ($value) {
            return $value !== null;
        }));

        return $this->makeRequest('GET', 'mods/search', $searchParams);
    }

    /**
     * Get details of a specific mod.
     *
     * @param int $modId
     * @return array
     * @throws ModsServiceException
     */
    public function getMod(int $modId): array
    {
        return $this->makeRequest('GET', 'mods/' . $modId);
    }

    /**
     * Get files for a specific mod.
     *
     * @param int $modId
     * @param array $params Filter parameters
     * @return array
     * @throws ModsServiceException
     */
    public function getModFiles(int $modId, array $params = []): array
    {
        $fileParams = array_filter([
            'gameVersion' => $params['gameVersion'] ?? null,
            'modLoaderType' => $params['modLoaderType'] ?? null,
            'pageSize' => min($params['pageSize'] ?? 20, config('modules.mods.max_page_size', 50)),
            'index' => $params['index'] ?? 0,
        ], function ($value) {
            return $value !== null;
        });

        return $this->makeRequest('GET', 'mods/' . $modId . '/files', $fileParams);
    }

    /**
     * Get details of a specific mod file.
     *
     * @param int $modId
     * @param int $fileId
     * @return array
     * @throws ModsServiceException
     */
    public function getModFile(int $modId, int $fileId): array
    {
        return $this->makeRequest('GET', 'mods/' . $modId . '/files/' . $fileId);
    }

    /**
     * Get download URL for a mod file.
     *
     * @param int $modId
     * @param int $fileId
     * @return string
     * @throws ModsServiceException
     */
    public function getModFileDownloadUrl(int $modId, int $fileId): string
    {
        $response = $this->makeRequest('GET', 'mods/' . $modId . '/files/' . $fileId . '/download-url');
        
        if (!isset($response['data'])) {
            throw new ModsServiceException('Failed to retrieve download URL from CurseForge API.');
        }

        return $response['data'];
    }

    /**
     * Get available Minecraft versions.
     *
     * @return array
     * @throws ModsServiceException
     */
    public function getMinecraftVersions(): array
    {
        // Game ID for Minecraft is 432
        return $this->makeRequest('GET', 'games/432/versions');
    }

    /**
     * Get available mod loader types.
     *
     * @return array
     * @throws ModsServiceException
     */
    public function getModLoaderTypes(): array
    {
        // Game ID for Minecraft is 432
        return $this->makeRequest('GET', 'games/432/version-types');
    }
}
