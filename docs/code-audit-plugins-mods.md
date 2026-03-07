# Code Audit — Plugins / Mods

> Comprehensive audit of plugin and mod installation, provider services, adapters, and frontend components.

---

## PM-1: Provider Adapter Anti-Pattern — Zero Value-Add Wrappers

- **Severity:** Medium
- **Category:** Architecture
- **Affected files:**
  - `app/Services/Plugins/Adapters/CurseForgeProviderAdapter.php` (57 lines)
  - `app/Services/Plugins/Adapters/ModrinthProviderAdapter.php` (59 lines)
  - `app/Services/Plugins/Adapters/SpigetProviderAdapter.php` (86 lines)
  - `app/Services/Plugins/ProviderAdapterInterface.php` (39 lines)
- **Problem:** All three adapters are thin delegation wrappers that add no transformation, validation, or abstraction. They simply forward calls to the underlying service (CurseForgeService, ModrinthService, SpigetService) with minor type casting. The `ProviderAdapterInterface` promises uniform behavior but implementations vary significantly in how they construct the response.
- **Why it matters:** Adds indirection without abstraction benefit. Developers must navigate through an extra layer to find the actual implementation.
- **Recommended refactor:** Remove adapters. Use a factory method directly in `PluginInstallService`:
  ```php
  public function getDownloadUrl(string $provider, ...): array {
      return match($provider) {
          'curseforge' => $this->curseForgeService->getDownloadUrl(...),
          'modrinth' => $this->modrinthService->getDownloadUrl(...),
          'spiget' => $this->spigetService->getDownloadUrl(...),
      };
  }
  ```
- **Estimated effort:** Small
- **Risk of change:** Low

---

## PM-2: Duplicate API Throttling — 70 Identical Lines

- **Severity:** Medium
- **Category:** Duplicate Code
- **Affected files:**
  - `app/Services/Mods/CurseForgeService.php` — `simpleThrottle()` (lines ~142-158)
  - `app/Services/Mods/ModrinthService.php` — `simpleThrottle()` (lines ~46-62)
- **Problem:** Both services contain identical `simpleThrottle()` implementations. The only difference is the cache key name (`curseforge_last_request_time` vs `modrinth_last_request_time`). Both use the same logic: check last request time from cache, sleep if below delay threshold, update cache.
- **Why it matters:** Any throttling improvement must be applied to both files.
- **Recommended refactor:** Extract to shared `HttpThrottler` trait or service:
  ```php
  trait HttpThrottled {
      protected function throttle(string $serviceKey, float $delaySeconds): void { ... }
  }
  ```
- **Suggested abstraction:** `App\Services\Mods\Traits\HttpThrottled`
- **Estimated effort:** Small
- **Risk of change:** Low

---

## PM-3: Duplicate Cached Request Logic — 95% Identical

- **Severity:** Medium
- **Category:** Duplicate Code
- **Affected files:**
  - `app/Services/Mods/CurseForgeService.php` — `makeCachedRequest()` (lines ~330-368)
  - `app/Services/Mods/ModrinthService.php` — `makeCachedRequest()` (lines ~158-189)
- **Problem:** Both services implement nearly identical caching logic: check `$this->cacheEnabled`, look up cached value, execute callback, serialize result, check size against 1MB max, cache if small enough. The only difference: CurseForge uses `Cache::lock()` to prevent cache stampede; Modrinth doesn't.
- **Why it matters:** Duplicate cache logic that should be standardized.
- **Recommended refactor:** Create shared `CachedProviderClient` trait or base class:
  ```php
  trait CachedProviderClient {
      protected function cached(string $key, int $ttl, callable $callback, bool $useLock = false): array { ... }
  }
  ```
- **Suggested abstraction:** `App\Services\Mods\Traits\CachedProviderClient`
- **Estimated effort:** Small
- **Risk of change:** Low

---

## PM-4: 8+ Identical Error Handling Blocks in ModsController

- **Severity:** Medium
- **Category:** Duplicate Code
- **Affected files:** `app/Http/Controllers/Api/Client/Servers/ModsController.php` (lines ~245-253, 270-278, 304-312, 326-342, 359-367, 384-392, 418-426, 440-448, 471-479)
- **Problem:** Eight or more endpoint methods contain identical try-catch blocks:
  ```php
  try {
      $result = $modService->someMethod($params);
      return response()->json($result);
  } catch (ModsServiceException $e) {
      return response()->json(['error' => $e->getMessage()], 500);
  }
  ```
  Enhanced versions add a second `catch (\Exception $e)` with logging.
- **Why it matters:** Adding new error handling (e.g., rate limit responses, retries) requires updating 8+ places.
- **Recommended refactor:** Create a wrapper method:
  ```php
  private function handleServiceCall(callable $callback): JsonResponse {
      try {
          return response()->json($callback());
      } catch (ModsServiceException $e) {
          return response()->json(['error' => $e->getMessage()], 400);
      } catch (\Exception $e) {
          Log::error('Unexpected error: ' . $e->getMessage());
          return response()->json(['error' => 'Unexpected error'], 500);
      }
  }
  ```
- **Estimated effort:** Small
- **Risk of change:** Low

---

## PM-5: 10+ Repeated Authorization Checks

- **Severity:** Medium
- **Category:** Duplicate Code
- **Affected files:** `app/Http/Controllers/Api/Client/Servers/ModsController.php` (lines ~224, 263, 288, 322, 352, 377, 402, 436, 458, 489)
- **Problem:** Every public method calls `checkProviderAllowed()` with the same pattern. Ten endpoints repeat:
  ```php
  $denied = $this->checkProviderAllowed($server, $source, 'mods');
  if ($denied) return $denied;
  ```
- **Why it matters:** If authorization logic changes, 10 places must be updated.
- **Recommended refactor:** Use middleware or a PHP attribute:
  ```php
  #[RequireProvider(resource: 'mods')]
  public function search(SearchModsRequest $request, Server $server): JsonResponse { ... }
  ```
  Or add authorization check in the controller's constructor/`__invoke` pattern.
- **Estimated effort:** Medium
- **Risk of change:** Medium

---

## PM-6: Duplicate Spiget Download Validation — 30+ Lines

- **Severity:** Medium
- **Category:** Duplicate Code
- **Affected files:** `app/Services/Plugins/PluginInstallService.php` (lines ~91-112 and ~139-146)
- **Problem:** SpigotMC redirect/block validation is performed twice: once as a preflight check before download, and again after the actual download. Both blocks check for `spigotmc.org` in the final redirect URL and `text/html` content type.
- **Why it matters:** Duplicate security validation code that must be kept in sync.
- **Recommended refactor:** Extract to private method:
  ```php
  private function validateSpigetDownloadUrl(string $url, array $headers): void { ... }
  ```
- **Estimated effort:** Small
- **Risk of change:** Low

---

## PM-7: `downloadModpack()` — 237-Line God Method

- **Severity:** High
- **Category:** Architecture
- **Affected files:** `app/Http/Controllers/Api/Client/Servers/ModsController.php` (lines ~487-723)
- **Problem:** Single method handling: size validation, download to temp, ZIP validation & extraction (with path traversal checks), manifest parsing, override directory upload, multi-mod download from manifest (nested loop with retries), and error aggregation & reporting.
- **Why it matters:** Untestable, hard to maintain, complex exception nesting (3+ levels), no rollback on partial failure.
- **Recommended refactor:** Extract into:
  - `ModpackExtractor` — ZIP validation + extraction
  - `ModpackModDownloader` — download mods from manifest
  - `ModpackInstaller` — orchestrate the full install flow
- **Suggested abstraction:** `App\Services\Mods\ModpackInstallService`
- **Estimated effort:** Medium
- **Risk of change:** Medium

---

## PM-8: Path Traversal Risk in Modpack Extraction

- **Severity:** High
- **Category:** Security
- **Affected files:** `app/Http/Controllers/Api/Client/Servers/ModsController.php` (lines ~558-602)
- **Problem:** ZIP entry paths are constructed before being validated. While `../` checks and `realpath()` comparisons exist, the normalization uses multiple chained string replacements that could miss edge cases. `realpath()` is only called on `$tempDir`, not on each extracted path.
- **Why it matters:** Malicious ZIP files could write outside the intended directory.
- **Recommended refactor:** Validate and normalize before construction. Use `realpath()` on each extracted path after creation to verify containment.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## PM-9: Temporary File Cleanup Not Guaranteed

- **Severity:** Medium
- **Category:** Security / Reliability
- **Affected files:**
  - `app/Services/Plugins/PluginInstallService.php` (lines ~79-171)
  - `app/Http/Controllers/Api/Client/Servers/ModsController.php` (lines ~524-526)
- **Problem:** PluginInstallService uses `@unlink($tempPath)` (suppressed error). ModsController creates temp dirs with `uniqid()` without checking for existence. Failed cleanup could leave sensitive files.
- **Why it matters:** Temp file leaks in production can consume disk space and expose data.
- **Recommended refactor:** Log cleanup failures instead of suppressing. Use `sys_get_temp_dir()` with proper cleanup in `finally` blocks.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## PM-10: Dead Code — SpigetService Empty Methods

- **Severity:** Low
- **Category:** Dead Code
- **Affected files:** `app/Services/Mods/SpigetService.php` (lines ~173-184)
- **Problem:** `getMinecraftVersions()` and `getModLoaderTypes()` always return `['data' => []]`. The frontend already skips these for Spigot sources.
- **Why it matters:** Unnecessary API calls and confusing code.
- **Recommended refactor:** Remove empty methods. Skip API calls for Spigot in the controller.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## PM-11: Unused Rate Limit Config

- **Severity:** Low
- **Category:** Dead Code
- **Affected files:** `config/modules/mods.php:24-27`
- **Problem:** Config defines `rate_limit.requests_per_minute` and `rate_limit.requests_per_hour` but services have hardcoded limits (CurseForge: 50 consecutive 429s trigger lockout; Modrinth: 300 req/min hardcoded).
- **Why it matters:** Config suggests configurability that doesn't exist.
- **Recommended refactor:** Either implement config-driven rate limiting or remove the unused config.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## PM-12: Inconsistent Service Method Signatures

- **Severity:** Low
- **Category:** Consistency
- **Affected files:**
  - `app/Services/Mods/CurseForgeService.php`
  - `app/Services/Mods/ModrinthService.php`
  - `app/Services/Mods/SpigetService.php`
- **Problem:** Services have inconsistent parameter types:
  | Method | CurseForge | Modrinth | Spiget |
  |--------|-----------|----------|---------|
  | `getMod()` | `int` | `string` | `int\|string` |
  | `getModFiles()` | `int, array` | `string` | `int\|string` |
  | `searchModpacks()` | ✓ | ✗ | ✗ |
  | `getDownloadUrl()` | Uses `getModFileDownloadUrl()` | ✓ | ✓ |
- **Why it matters:** No type safety at the adapter/factory level. Inconsistent casting in controllers.
- **Recommended refactor:** Define `ModProviderInterface` with consistent signatures using `string|int`.
- **Estimated effort:** Medium
- **Risk of change:** Medium

---

## PM-13: Silent Failures in Installed Addons Scan

- **Severity:** Low
- **Category:** Reliability / UX
- **Affected files:** `app/Http/Controllers/Api/Client/Servers/ModsController.php` — `listDirectorySafely()` (lines ~820-829)
- **Problem:** Returns `null` on any error (server unreachable, permissions, etc.). Caller cannot distinguish between "no addons installed" and "server offline."
- **Why it matters:** Users see an empty list without understanding the reason.
- **Recommended refactor:** Distinguish between `NotFoundException` (return empty array) and other errors (return error response).
- **Estimated effort:** Small
- **Risk of change:** Low

---

## PM-14: Frontend — Parallel UI Structures for Mods and Installed Addons

- **Severity:** Low
- **Category:** Duplicate Code
- **Affected files:**
  - `resources/scripts/components/server/mods/ModsContainer.tsx` (243 lines)
  - `resources/scripts/components/server/plugins/InstalledAddonsList.tsx` (248 lines)
- **Problem:** Both components implement nearly identical UI structures: pagination (50 items), search debounce, status filtering, loading states, card grids. They solve the same browsing/filtering problem with parallel implementations.
- **Why it matters:** UI inconsistency if one is updated but not the other.
- **Recommended refactor:** Create shared `AddonBrowser` component that accepts a content type parameter and data source callback.
- **Estimated effort:** Medium
- **Risk of change:** Medium

---

## PM-15: Frontend — ModDownloadButton Not Reused

- **Severity:** Low
- **Category:** Duplicate Code
- **Affected files:**
  - `resources/scripts/components/server/mods/ModDownloadButton.tsx` (79 lines)
  - `resources/scripts/components/server/mods/ModDetails.tsx` (lines ~217-250)
  - `resources/scripts/components/server/mods/ModList.tsx` (line ~80)
- **Problem:** `ModDownloadButton` is a reusable component, but `ModDetails` implements its own inline download handler instead of using it. Both duplicate loading/error state management.
- **Why it matters:** Download behavior may diverge between the list view and detail view.
- **Recommended refactor:** Use `ModDownloadButton` in `ModDetails` as well.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## PM-16: API Key Logging Risk in URL Sanitization

- **Severity:** Low
- **Category:** Security
- **Affected files:** `app/Services/Plugins/PluginInstallService.php` (lines ~385-398)
- **Problem:** `sanitizeUrlForLogging()` strips scheme, host, and path but may not strip query string parameters. If any provider embeds API keys in URLs, they could be logged.
- **Why it matters:** API key exposure in logs.
- **Recommended refactor:** Explicitly omit query string and fragment from sanitized URL.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## PM-17: Incomplete Modpack Feature

- **Severity:** Low
- **Category:** Dead Code / Incomplete Feature
- **Affected files:**
  - `resources/scripts/components/server/plugins/ModsAndPluginsPage.tsx` (line ~38) — shows "coming soon"
  - `app/Http/Controllers/Api/Client/Servers/ModsController.php` — full download implementation exists
- **Problem:** Frontend marks modpacks as "coming soon" but backend implementation exists (237 lines of complex logic including ZIP extraction and manifest processing). Only CurseForge is supported for modpacks.
- **Why it matters:** Complex, untested code in production that's not exposed to users.
- **Recommended refactor:** Either finish and enable the feature, or remove the backend implementation to reduce maintenance burden and security surface area.
- **Estimated effort:** Medium (to finish) or Small (to remove)
- **Risk of change:** Medium
