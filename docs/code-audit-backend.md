# Code Audit — Backend Patterns

> Comprehensive audit of Laravel controllers, services, models, transformers, and shared backend patterns.

---

## BE-1: Pagination Validation Duplicated in 38+ Controllers

- **Severity:** High
- **Category:** Duplicate Code
- **Affected files:**
  - `app/Http/Controllers/Api/Application/Users/UserController.php:55-57`
  - `app/Http/Controllers/Api/Application/Roles/RoleController.php:35-38`
  - `app/Http/Controllers/Api/Application/Billing/CouponController.php:33-36`
  - `app/Http/Controllers/Api/Application/Billing/CategoryController.php:37-40`
  - `app/Http/Controllers/Api/Application/Billing/ProductController.php:37-40`
  - `app/Http/Controllers/Api/Application/Nests/NestController.php:43-46`
  - `app/Http/Controllers/Api/Application/Eggs/EggController.php:38-41`
  - Plus 30+ more controllers
- **Problem:** Same 3-line pattern repeated 38+ times:
  ```php
  $perPage = (int) $request->query('per_page', '20');
  if ($perPage < 1 || $perPage > 100) {
      throw new QueryValueOutOfRangeHttpException('per_page', 1, 100);
  }
  ```
  Variations exist: some use `min()`, some default to 25 or 50 or 20. Inconsistent defaults.
- **Why it matters:** 150+ duplicate lines. Inconsistent defaults confuse API consumers.
- **Recommended refactor:** Add to base `ApplicationApiController`:
  ```php
  protected function getPaginationLimit(Request $request, int $default = 20, int $max = 100): int
  {
      $perPage = (int) $request->query('per_page', (string) $default);
      if ($perPage < 1 || $perPage > $max) {
          throw new QueryValueOutOfRangeHttpException('per_page', 1, $max);
      }
      return $perPage;
  }
  ```
- **Suggested abstraction:** Base controller method
- **Estimated effort:** Small
- **Risk of change:** Low

---

## BE-2: Debug Code in Production — `dd()` in RoleController

- **Severity:** Critical
- **Category:** Bug / Dead Code
- **Affected files:** `app/Http/Controllers/Api/Application/Roles/RoleController.php:105`
- **Problem:**
  ```php
  public function updatePermissions(UpdateRoleRequest $request, AdminRole $role): array
  {
      dd($request->input('permissions'));  // DUMP AND DIE
      return $this->fractal->item($role)  // Dead code
          ->transformWith(AdminRoleTransformer::class)
          ->toArray();
  }
  ```
- **Why it matters:** The `PATCH /api/application/roles/{id}/permissions` route is completely broken. The function after `dd()` never executes.
- **Recommended refactor:** Remove `dd()` and implement the actual permission update:
  ```php
  $role->update(['permissions' => $request->input('permissions')]);
  ```
- **Estimated effort:** Small
- **Risk of change:** Low

---

## BE-3: Duplicate ActivityLog Transformers — 150+ Lines

- **Severity:** High
- **Category:** Duplicate Code
- **Affected files:**
  - `app/Transformers/Api/Application/ActivityLogTransformer.php` (210 lines)
  - `app/Transformers/Api/Client/ActivityLogTransformer.php` (220 lines)
- **Problem:** 90%+ identical code. Shared methods: `includeActor()`, `properties()`, `hasAdditionalMetadata()`, `scope()`, `inferScope()`, `context()`, `category()`, `source()`, `severity()`. Only differences: Client version has `canViewIP()` method and slightly different timestamp formatting.
- **Why it matters:** Any fix or enhancement must be made in both files. High risk of divergence.
- **Recommended refactor:** Create `BaseActivityLogTransformer` abstract class with shared methods. Application and Client versions extend it, overriding only `transform()`.
- **Suggested abstraction:** `App\Transformers\Api\BaseActivityLogTransformer`
- **Estimated effort:** Small
- **Risk of change:** Low

---

## BE-4: Duplicate ActivityLog Filtering/Query Patterns

- **Severity:** High
- **Category:** Duplicate Code
- **Affected files:**
  - `app/Http/Controllers/Api/Application/ActivityLogController.php:18-27`
  - `app/Http/Controllers/Api/Client/ActivityLogController.php:20-30`
  - `app/Http/Controllers/Api/Client/Servers/ActivityLogController.php:97-145`
- **Problem:** Complex scope filtering queries repeated 3+ times:
  ```php
  ->where(function ($query) {
      $query->where('scope', 'admin')
          ->orWhere(function ($sub) {
              $sub->where('scope', 'server')->where('is_admin', true);
          })
          ->orWhere(function ($sub) {
              $sub->whereNull('scope')->where('is_admin', true);
          });
  })
  ```
  Search filter callbacks also duplicated.
- **Why it matters:** 80+ duplicate lines. Business logic for scope filtering is scattered across controllers instead of being centralized.
- **Recommended refactor:** Create query scopes in `ActivityLog` model:
  ```php
  public function scopeAdminActivity($query) { ... }
  public function scopeClientActivity($query, $userId) { ... }
  public function scopeServerActivity($query, $serverId) { ... }
  public function scopeSearchable($query, $searchTerm) { ... }
  ```
- **Suggested abstraction:** Model query scopes
- **Estimated effort:** Small
- **Risk of change:** Low

---

## BE-5: Duplicate Activity Logging Patterns — 30+ Controllers

- **Severity:** Medium
- **Category:** Duplicate Code
- **Affected files:** 30+ controllers including:
  - `app/Http/Controllers/Api/Application/Servers/ServerController.php:74-77`
  - `app/Http/Controllers/Api/Application/Users/UserController.php:156-159`
  - `app/Http/Controllers/Api/Application/Nodes/NodeController.php:76-79`
  - `app/Http/Controllers/Api/Application/Billing/CouponController.php:66-69`
- **Problem:** Same activity logging structure repeated for every CRUD operation:
  ```php
  Activity::event('admin:X:create')
      ->property('resource', $resource)
      ->description('A resource was created')
      ->log();
  ```
- **Why it matters:** 200+ lines of repetitive logging code.
- **Recommended refactor:** Create `LogsActivities` trait:
  ```php
  trait LogsActivities {
      protected function logCreation(string $resource, Model $model): void { ... }
      protected function logUpdate(string $resource, Model $model, array $changes): void { ... }
      protected function logDeletion(string $resource, Model $model): void { ... }
  }
  ```
- **Suggested abstraction:** `App\Http\Controllers\Traits\LogsActivities`
- **Estimated effort:** Medium
- **Risk of change:** Low

---

## BE-6: Inconsistent Error Handling — Generic Exceptions

- **Severity:** Medium
- **Category:** Consistency
- **Affected files:**
  - `app/Http/Controllers/Api/Application/Billing/ProductController.php:85, 125`
  - `app/Http/Controllers/Api/Application/Billing/CategoryController.php:76, 112`
  - `app/Http/Controllers/Api/Application/IntelligenceController.php:53`
  - `app/Http/Controllers/Api/Application/Users/UserController.php:174`
  - `app/Http/Controllers/Api/Application/Servers/ServerManagementController.php:73`
- **Problem:** Generic `\Exception` thrown instead of domain-specific exceptions:
  ```php
  throw new \Exception('Failed to create a new product: ' . $ex->getMessage());
  throw new \Exception('You cannot suspend an administrator.');
  ```
  Mixed with proper `DisplayException` usage in other places.
- **Why it matters:** Generic exceptions return 500 errors to users. `DisplayException` returns 400 with user-friendly messages. Inconsistent behavior.
- **Recommended refactor:** Use `DisplayException` for user-facing errors. Create domain exceptions for internal errors. Never throw generic `\Exception` for business logic.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## BE-7: Insufficient Transaction Usage

- **Severity:** Medium
- **Category:** Reliability
- **Affected files:** Many services that modify multiple records
- **Problem:** Only 4 uses of `DB::transaction` found across 86+ services. Missing transactions in:
  - Product creation/update (multi-step with category linkage)
  - Order processing (order + server creation + coupon usage)
  - User operations that modify multiple related records
- **Why it matters:** Partial data corruption if a multi-step operation fails midway.
- **Recommended refactor:** Wrap all multi-record operations in transactions. Create a service convention for transactional methods.
- **Estimated effort:** Medium
- **Risk of change:** Medium

---

## BE-8: Inconsistent Response Formatting

- **Severity:** Low
- **Category:** Consistency
- **Affected files:** Multiple controllers
- **Problem:** Different ways to specify HTTP status codes:
  ```php
  ->respond(Response::HTTP_CREATED);    // UserController
  ->respond(JsonResponse::HTTP_CREATED); // RoleController
  ->respond(201);                        // NodeController
  ```
- **Why it matters:** Inconsistent coding style. Developers unsure which constant to use.
- **Recommended refactor:** Standardize on `Response::HTTP_*` constants across all controllers.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## BE-9: CRUD Route Boilerplate — 32+ Identical Groups

- **Severity:** Low
- **Category:** Duplicate Code
- **Affected files:** `routes/api-application.php`
- **Problem:** 32+ route groups with identical CRUD structure:
  ```php
  Route::group(['prefix' => '/X'], function () {
      Route::get('/', [..., 'index']);
      Route::post('/', [..., 'store']);
      Route::get('/{X:id}', [..., 'view']);
      Route::patch('/{X:id}', [..., 'update']);
      Route::delete('/{X:id}', [..., 'delete']);
  });
  ```
- **Why it matters:** Repetitive route definitions.
- **Recommended refactor:** Create a route macro:
  ```php
  Route::crudResource('coupons', CouponController::class);
  ```
- **Estimated effort:** Small
- **Risk of change:** Low

---

## BE-10: Inconsistent Request Validation Patterns

- **Severity:** Medium
- **Category:** Consistency
- **Affected files:** 199+ request files in `app/Http/Requests/`
- **Problem:** Multiple approaches to validation rule organization:
  1. Model's static `getRules()`: `User::getRules()` in `StoreUserRequest`
  2. Model's `$validationRules`: Used in `StoreCouponRequest`
  3. Inline rules directly in request class
  4. Parent inheritance with override
- **Why it matters:** No consistent pattern for new developers to follow.
- **Recommended refactor:** Standardize on one approach (preferably model static rules with request-specific overrides).
- **Estimated effort:** Large
- **Risk of change:** Medium

---

## BE-11: Duplicate Order Query Controllers (Application vs Client)

- **Severity:** Medium
- **Category:** Duplicate Code
- **Affected files:**
  - `app/Http/Controllers/Api/Application/Billing/OrderController.php:27-67`
  - `app/Http/Controllers/Api/Client/Billing/OrderController.php:24-63`
- **Problem:** 90% identical query builder code. Same filters, sorts, and search callbacks. Only difference: client adds `where('user_id', ...)`.
- **Why it matters:** Any filter or sort change must be applied to both.
- **Recommended refactor:** Create `OrderQueryService` with optional user scoping.
- **Suggested abstraction:** `App\Services\Billing\OrderQueryService`
- **Estimated effort:** Small
- **Risk of change:** Low

---

## BE-12: TODO Comments in Production Code

- **Severity:** Low
- **Category:** Dead Code / Incomplete
- **Affected files:**
  - `app/Http/Controllers/Api/Application/Eggs/EggController.php:75` — `"TODO: allow this to be set in the request"`
  - `app/Http/Controllers/Api/Application/Billing/ProductController.php:62` — `"TODO(jex): clean this up, make a service or somethin'"`
- **Problem:** Incomplete features left with TODO comments. No tracking in issue tracker.
- **Why it matters:** TODOs accumulate and are forgotten.
- **Recommended refactor:** Convert TODOs to GitHub issues with proper tracking. Fix or remove them.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## BE-13: Minimal Policy Implementation

- **Severity:** Low
- **Category:** Architecture
- **Affected files:** `app/Policies/ServerPolicy.php` (only policy file)
- **Problem:** Only one policy file exists for the entire codebase. It uses a magic `__call()` method to avoid defining all permission methods. All other authorization is handled in controllers or middleware.
- **Why it matters:** Authorization logic is scattered instead of centralized in policies.
- **Recommended refactor:** Add policies for User, Order, Server operations. Centralize authorization checks.
- **Estimated effort:** Large
- **Risk of change:** High

---

## BE-14: ModsController Exceeds 1000 Lines

- **Severity:** High
- **Category:** Architecture
- **Affected files:** `app/Http/Controllers/Api/Client/Servers/ModsController.php` (1,029 lines)
- **Problem:** Single controller handling: mod search, mod details, mod files, mod download, Minecraft versions, mod loader types, modpack search, modpack details, modpack files, modpack download, installed addons listing, addon toggle, addon icon, provider capabilities. This is a "god controller."
- **Why it matters:** Extremely hard to navigate, test, or modify safely.
- **Recommended refactor:** Split into:
  - `ModSearchController` — search, details, files, versions
  - `ModInstallController` — download, toggle
  - `ModpackController` — search, details, download
  - `InstalledAddonsController` — list, toggle, icons
- **Estimated effort:** Medium
- **Risk of change:** Medium
