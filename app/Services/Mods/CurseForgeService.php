<?php

namespace Everest\Services\Mods;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Everest\Exceptions\Service\Mods\ModsServiceException;
use Everest\Models\CurseForgeRequestLog;

class CurseForgeService
{
    private Client $client;
    private string $apiKey;
    private string $endpoint;
    private bool $cacheEnabled;
    private array $cacheTtl;
    private float $requestDelaySeconds = 1.5; // Simple 1-2 second delay between requests (avg 1.5)
    private int $max429BeforeLockout = 50; // Lock out after 50 consecutive 429s
    private int $lockoutDurationSeconds = 86400; // 24 hours lockout

    /**
     * CurseForgeService constructor.
     */
    public function __construct()
    {
        $this->apiKey = config('modules.mods.curseforge_api_key') ?: '';
        $this->endpoint = config('modules.mods.curseforge_api_url') ?: 'https://api.curseforge.com/v1';
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
     * Check if CurseForge API is locked out due to excessive 429 errors.
     *
     * @throws ModsServiceException
     */
    private function checkLockout(): void
    {
        $lockoutKey = 'curseforge_lockout_until';
        $lockoutUntil = Cache::get($lockoutKey);
        
        if ($lockoutUntil && time() < $lockoutUntil) {
            $remainingSeconds = $lockoutUntil - time();
            $remainingHours = round($remainingSeconds / 3600, 1);
            throw new ModsServiceException("CurseForge API is locked out due to excessive rate limiting. Try again in {$remainingHours} hours.");
        }
    }

    /**
     * Track 429 errors and trigger lockout if threshold is reached.
     *
     * @return void
     */
    private function track429Error(): void
    {
        $counterKey = 'curseforge_429_counter';
        $count = Cache::get($counterKey, 0) + 1;
        
        // Store count with 1 hour expiry (resets if we go without 429s)
        Cache::put($counterKey, $count, 3600);
        
        Log::warning("CurseForge 429 error count: {$count}/{$this->max429BeforeLockout}");
        
        // If we hit the threshold, trigger 24-hour lockout
        if ($count >= $this->max429BeforeLockout) {
            $lockoutUntil = time() + $this->lockoutDurationSeconds;
            Cache::put('curseforge_lockout_until', $lockoutUntil, $this->lockoutDurationSeconds);
            Cache::forget($counterKey); // Reset counter
            
            Log::error("CurseForge API locked out for 24 hours after {$count} consecutive 429 errors");
        }
    }

    /**
     * Reset 429 error counter on successful request.
     *
     * @return void
     */
    private function reset429Counter(): void
    {
        Cache::forget('curseforge_429_counter');
    }

    /**
     * Simple throttling - just space requests 1-2 seconds apart.
     *
     * @return void
     */
    private function simpleThrottle(): void
    {
        $lastRequestKey = 'curseforge_last_request_time';
        $lastRequestTime = Cache::get($lastRequestKey, 0);
        $now = microtime(true);
        
        $timeSinceLastRequest = $now - $lastRequestTime;
        
        // Wait if we haven't waited long enough
        if ($timeSinceLastRequest < $this->requestDelaySeconds) {
            $sleepTime = $this->requestDelaySeconds - $timeSinceLastRequest;
            usleep((int) ($sleepTime * 1000000));
        }
        
        // Update last request time
        Cache::put($lastRequestKey, microtime(true), 3600);
    }

    /**
     * Track a CurseForge API request in the database.
     *
     * @param string $endpoint
     * @param int $statusCode
     * @return void
     */
    private function trackRequest(string $endpoint, int $statusCode): void
    {
        try {
            CurseForgeRequestLog::create([
                'requested_at' => now(),
                'endpoint' => $endpoint,
                'status_code' => $statusCode,
            ]);
            
            // Clean up old logs (older than 25 hours to ensure we have data for the last full hour)
            CurseForgeRequestLog::where('requested_at', '<', now()->subHours(25))->delete();
        } catch (\Exception $e) {
            // Log error but don't fail the request
            Log::error('Failed to track CurseForge request: ' . $e->getMessage());
        }
    }

    /**
     * Get current rate limit usage with hourly and daily analytics.
     *
     * @return array
     */
    public function getRateLimitUsage(): array
    {
        $now = now();
        $oneMinuteAgo = $now->copy()->subMinute();
        $oneHourAgo = $now->copy()->subHour();
        
        // Get requests in the last minute
        $requestsThisMinute = CurseForgeRequestLog::where('requested_at', '>=', $oneMinuteAgo)->count();
        
        // Get requests in the last hour
        $requestsThisHour = CurseForgeRequestLog::where('requested_at', '>=', $oneHourAgo)->count();
        
        // CurseForge rate limits (as per their API documentation)
        // These are conservative estimates - actual limits may vary
        $limitPerMinute = 20; // Conservative estimate
        $limitPerHour = 1000; // Conservative estimate
        
        return [
            'requests_this_minute' => $requestsThisMinute,
            'requests_this_hour' => $requestsThisHour,
            'limit_per_minute' => $limitPerMinute,
            'limit_per_hour' => $limitPerHour,
        ];
    }

    /**
     * Get legacy 429 error tracking data (deprecated but kept for compatibility).
     *
     * @return array
     */
    public function get429ErrorTracking(): array
    {
        $counter429 = Cache::get('curseforge_429_counter', 0);
        $lockoutUntil = Cache::get('curseforge_lockout_until');
        
        return [
            '429_errors' => $counter429,
            'max_429_before_lockout' => $this->max429BeforeLockout,
            'locked_out' => $lockoutUntil && time() < $lockoutUntil,
            'lockout_until' => $lockoutUntil ? date('Y-m-d H:i:s', $lockoutUntil) : null,
        ];
    }

    /**
     * Make a request to the CurseForge API with simple throttling and 429-based lockout.
     *
     * @throws ModsServiceException
     */
    private function makeRequest(string $method, string $path, array $params = [], int $retryAttempt = 0): array
    {
        if (empty($this->apiKey)) {
            throw new ModsServiceException('CurseForge API key is not configured.');
        }

        // Check if we're in lockout period
        $this->checkLockout();

        // Acquire lock to serialize all API requests (max concurrency = 1)
        if (!$this->acquireApiLock()) {
            throw new ModsServiceException('Failed to acquire API lock for CurseForge request.');
        }

        // Simple throttling: space requests 1-2 seconds apart
        $this->simpleThrottle();

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
            
            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('CurseForge API JSON decode error: ' . json_last_error_msg());
                throw new ModsServiceException('Failed to decode CurseForge API response.');
            }

            // Track successful request
            $this->trackRequest($path, $statusCode);

            // Reset 429 counter on successful request
            $this->reset429Counter();

            return $data;
        } catch (GuzzleException $e) {
            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                
                // Handle 429 with delay and tracking
                if ($statusCode === 429) {
                    // Track this 429 error in the database
                    $this->trackRequest($path, $statusCode);
                    
                    // Track this 429 error for lockout purposes
                    $this->track429Error();
                    
                    // Get current counter to determine delay
                    $count = Cache::get('curseforge_429_counter', 0);
                    
                    // If we're near the threshold, use longer delays (30-60s)
                    if ($count >= $this->max429BeforeLockout - 10) {
                        $delay = rand(30, 60); // 30-60 second delay when approaching lockout
                        Log::warning("CurseForge 429 (near threshold), waiting {$delay}s before retry");
                    } else {
                        $delay = rand(5, 10); // 5-10 second delay for normal 429s
                        Log::warning("CurseForge 429, waiting {$delay}s before retry");
                    }
                    
                    // Only retry a few times per call to avoid infinite loops
                    if ($retryAttempt < 3) {
                        sleep($delay);
                        return $this->makeRequest($method, $path, $params, $retryAttempt + 1);
                    } else {
                        throw new ModsServiceException('CurseForge API rate limit (429) exceeded after retries.');
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
     * Make a cached request to the CurseForge API with memory-safe handling.
     *
     * @throws ModsServiceException
     */
    private function makeCachedRequest(string $cacheKey, int $ttl, callable $requestCallback): array
    {
        if (!$this->cacheEnabled) {
            return $requestCallback();
        }

        // Check if cached data exists
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Execute the request
        $data = $requestCallback();
        
        // Only cache if data size is reasonable (< 1MB when serialized)
        $serialized = serialize($data);
        $sizeInBytes = strlen($serialized);
        $maxCacheSize = 1048576; // 1MB limit
        
        if ($sizeInBytes < $maxCacheSize) {
            try {
                Cache::put($cacheKey, $data, $ttl);
            } catch (\Exception $e) {
                // If caching fails due to memory, log but continue
                Log::warning("Failed to cache CurseForge response (size: {$sizeInBytes} bytes): " . $e->getMessage());
            }
        } else {
            Log::info("Skipping cache for large response (size: {$sizeInBytes} bytes, key: {$cacheKey})");
        }
        
        return $data;
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
            'classId' => 4471, // Modpacks class - REQUIRED for modpack searches
            'pageSize' => min($params['pageSize'] ?? 20, config('modules.mods.max_page_size', 50)),
        ];

        // Only include valid modpack search parameters
        $searchParams = array_merge($defaultParams, array_filter([
            'searchFilter' => $params['searchFilter'] ?? null,
            'sortField' => $params['sortField'] ?? null,
            'sortOrder' => $params['sortOrder'] ?? null,
            'index' => $params['index'] ?? 0,
        ], function ($value) {
            // Filter out null and empty strings to prevent AND-filter conflicts
            return $value !== null && $value !== '';
        }));

        // Log the search parameters for debugging
        Log::info('CurseForge modpack search params:', $searchParams);

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
