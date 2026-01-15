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
    private bool $cacheEnabled;
    private array $cacheTtl;
    private int $baseThrottleSeconds = 10; // Base: 1 request every 10 seconds
    private int $slowThrottleSeconds = 17; // Slow: 1 request every 15-20 seconds (avg 17.5)

    /**
     * CurseForgeService constructor.
     */
    public function __construct()
    {
        $this->apiKey = config('modules.mods.curseforge_api_key') ?: '';
        $this->endpoint = config('modules.mods.curseforge_api_url') ?: 'https://api.curseforge.com/v1';
        $this->requestsPerMinute = config('modules.mods.rate_limit.requests_per_minute', 30);
        $this->requestsPerHour = config('modules.mods.rate_limit.requests_per_hour', 1800);
        $this->cacheEnabled = config('modules.mods.cache.enabled', true);
        
        // Aggressive 24-hour caching for all API responses
        $this->cacheTtl = config('modules.mods.cache.ttl', [
            'search' => 86400,      // 24 hours
            'mod_details' => 86400, // 24 hours
            'mod_files' => 86400,   // 24 hours
            'versions' => 86400,    // 24 hours
            'loaders' => 86400,     // 24 hours
        ]);

        $this->client = new Client([
            'base_uri' => rtrim($this->endpoint, '/') . '/',
            'timeout' => 30,
        ]);
    }

    /**
     * Acquire a lock to serialize API requests (max concurrency = 1).
     *
     * @return bool
     */
    private function acquireApiLock(): bool
    {
        $lockKey = 'curseforge_api_lock';
        $lockAcquired = Cache::lock($lockKey, 60)->get(function () {
            // Lock acquired, can proceed with API request
            return true;
        });

        if (!$lockAcquired) {
            // Wait a bit and retry once
            usleep(500000); // 500ms
            return Cache::lock($lockKey, 60)->get(function () {
                return true;
            });
        }

        return $lockAcquired;
    }

    /**
     * Enforce throttling based on rate limit headers and base rate.
     *
     * @param array|null $lastHeaders Rate limit headers from last request
     * @throws ModsServiceException
     */
    private function enforceThrottling(?array $lastHeaders = null): void
    {
        $lastRequestKey = 'curseforge_last_request_time';
        $lastRequestTime = Cache::get($lastRequestKey, 0);
        $now = microtime(true);
        
        // Determine throttle delay based on rate limit headers
        $throttleSeconds = $this->baseThrottleSeconds;
        
        if ($lastHeaders && isset($lastHeaders['x-rl-hourly-remaining'])) {
            $remaining = (int) $lastHeaders['x-rl-hourly-remaining'][0];
            
            // If remaining quota is low, slow down significantly
            if ($remaining < 100) {
                $throttleSeconds = $this->slowThrottleSeconds;
                Log::info("CurseForge hourly quota low ({$remaining} remaining), slowing to {$throttleSeconds}s per request");
            }
        }
        
        // Calculate time since last request
        $timeSinceLastRequest = $now - $lastRequestTime;
        
        // If we haven't waited long enough, sleep for the remaining time
        if ($timeSinceLastRequest < $throttleSeconds) {
            $sleepTime = $throttleSeconds - $timeSinceLastRequest;
            Log::debug("Throttling CurseForge API: sleeping for {$sleepTime}s");
            usleep((int) ($sleepTime * 1000000));
        }
        
        // Update last request time
        Cache::put($lastRequestKey, microtime(true), 3600);
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
     * Make a request to the CurseForge API with serialization, throttling, and exponential backoff.
     *
     * @throws ModsServiceException
     */
    private function makeRequest(string $method, string $path, array $params = [], int $retryAttempt = 0): array
    {
        if (empty($this->apiKey)) {
            throw new ModsServiceException('CurseForge API key is not configured.');
        }

        // Acquire lock to serialize all API requests (max concurrency = 1)
        if (!$this->acquireApiLock()) {
            throw new ModsServiceException('Failed to acquire API lock for CurseForge request.');
        }

        // Get last rate limit headers for dynamic throttling
        $lastHeaders = Cache::get('curseforge_last_rate_headers');
        
        // Enforce throttling (1 req/10s base, 1 req/15-20s if quota low)
        $this->enforceThrottling($lastHeaders);
        
        // Check internal rate limits
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
            
            // Store rate limit headers for next request
            $headers = $response->getHeaders();
            if (isset($headers['x-rl-hourly-remaining'])) {
                Cache::put('curseforge_last_rate_headers', $headers, 3600);
            }
            
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('CurseForge API JSON decode error: ' . json_last_error_msg());
                throw new ModsServiceException('Failed to decode CurseForge API response.');
            }

            return $data;
        } catch (GuzzleException $e) {
            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                
                // Handle 429 with exponential backoff: 30s, 60s, 120s
                if ($statusCode === 429) {
                    if ($retryAttempt < 3) {
                        $backoffDelays = [30, 60, 120];
                        $delay = $backoffDelays[$retryAttempt];
                        
                        Log::warning("CurseForge API rate limit (429), attempt {$retryAttempt}, waiting {$delay}s before retry");
                        sleep($delay);
                        
                        // Retry with incremented attempt counter
                        return $this->makeRequest($method, $path, $params, $retryAttempt + 1);
                    } else {
                        Log::error('CurseForge API rate limit exceeded after 3 retries with exponential backoff');
                        throw new ModsServiceException('CurseForge API rate limit exceeded. Please try again later.');
                    }
                } elseif ($statusCode === 401 || $statusCode === 403) {
                    throw new ModsServiceException('Invalid CurseForge API key.');
                }
            }
            
            Log::error('CurseForge API request failed: ' . $e->getMessage());
            throw new ModsServiceException('Failed to connect to CurseForge API: ' . $e->getMessage());
        }
    }

    /**
     * Make a cached request to the CurseForge API.
     *
     * @throws ModsServiceException
     */
    private function makeCachedRequest(string $cacheKey, int $ttl, callable $requestCallback): array
    {
        if (!$this->cacheEnabled) {
            return $requestCallback();
        }

        return Cache::remember($cacheKey, $ttl, $requestCallback);
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

        // Create cache key based on search parameters
        $cacheKey = 'curseforge_search_' . md5(json_encode($searchParams));
        
        return $this->makeCachedRequest($cacheKey, $this->cacheTtl['search'], function () use ($searchParams) {
            return $this->makeRequest('GET', 'mods/search', $searchParams);
        });
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
        $cacheKey = "curseforge_mod_{$modId}";
        
        return $this->makeCachedRequest($cacheKey, $this->cacheTtl['mod_details'], function () use ($modId) {
            return $this->makeRequest('GET', 'mods/' . $modId);
        });
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

        $cacheKey = "curseforge_mod_files_{$modId}_" . md5(json_encode($fileParams));
        
        return $this->makeCachedRequest($cacheKey, $this->cacheTtl['mod_files'], function () use ($modId, $fileParams) {
            return $this->makeRequest('GET', 'mods/' . $modId . '/files', $fileParams);
        });
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
        $cacheKey = 'curseforge_minecraft_versions';
        
        return $this->makeCachedRequest($cacheKey, $this->cacheTtl['versions'], function () {
            // Game ID for Minecraft is 432
            return $this->makeRequest('GET', 'games/432/versions');
        });
    }

    /**
     * Get available mod loader types.
     *
     * @return array
     * @throws ModsServiceException
     */
    public function getModLoaderTypes(): array
    {
        $cacheKey = 'curseforge_mod_loaders';
        
        return $this->makeCachedRequest($cacheKey, $this->cacheTtl['loaders'], function () {
            // Get Minecraft mod loaders (Forge, Fabric, NeoForge, etc.)
            return $this->makeRequest('GET', 'minecraft/modloader');
        });
    }

    /**
     * Search for modpacks in the CurseForge database.
     *
     * @param array $params Search parameters
     * @return array
     * @throws ModsServiceException
     */
    public function searchModpacks(array $params = []): array
    {
        // CurseForge Minecraft game ID is 432
        $defaultParams = [
            'gameId' => 432,
            'classId' => 4471, // Modpacks class
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

        // Create cache key based on search parameters
        $cacheKey = 'curseforge_modpack_search_' . md5(json_encode($searchParams));
        
        return $this->makeCachedRequest($cacheKey, $this->cacheTtl['search'], function () use ($searchParams) {
            return $this->makeRequest('GET', 'mods/search', $searchParams);
        });
    }

    /**
     * Get details of a specific modpack.
     *
     * @param int $modpackId
     * @return array
     * @throws ModsServiceException
     */
    public function getModpack(int $modpackId): array
    {
        $cacheKey = "curseforge_modpack_{$modpackId}";
        
        return $this->makeCachedRequest($cacheKey, $this->cacheTtl['mod_details'], function () use ($modpackId) {
            return $this->makeRequest('GET', 'mods/' . $modpackId);
        });
    }

    /**
     * Get files for a specific modpack.
     *
     * @param int $modpackId
     * @param array $params Filter parameters
     * @return array
     * @throws ModsServiceException
     */
    public function getModpackFiles(int $modpackId, array $params = []): array
    {
        $fileParams = array_filter([
            'gameVersion' => $params['gameVersion'] ?? null,
            'modLoaderType' => $params['modLoaderType'] ?? null,
            'pageSize' => min($params['pageSize'] ?? 20, config('modules.mods.max_page_size', 50)),
            'index' => $params['index'] ?? 0,
        ], function ($value) {
            return $value !== null;
        });

        $cacheKey = "curseforge_modpack_files_{$modpackId}_" . md5(json_encode($fileParams));
        
        return $this->makeCachedRequest($cacheKey, $this->cacheTtl['mod_files'], function () use ($modpackId, $fileParams) {
            return $this->makeRequest('GET', 'mods/' . $modpackId . '/files', $fileParams);
        });
    }

    /**
     * Get details of a specific modpack file.
     *
     * @param int $modpackId
     * @param int $fileId
     * @return array
     * @throws ModsServiceException
     */
    public function getModpackFile(int $modpackId, int $fileId): array
    {
        return $this->makeRequest('GET', 'mods/' . $modpackId . '/files/' . $fileId);
    }
}
