# Code Audit — RBAC, Validation & Security

> Comprehensive audit of role-based access control, request validation, authorization checks, and security patterns.

---

## RV-1: Only One Policy File for Entire Codebase

- **Severity:** Medium
- **Category:** Architecture / Security
- **Affected files:** `app/Policies/ServerPolicy.php`
- **Problem:** The entire codebase has only one policy file (`ServerPolicy.php`). All other authorization is handled directly in controllers or middleware. The `ServerPolicy` uses a magic `__call()` method to delegate permission checks instead of defining explicit methods.
- **Why it matters:** Authorization logic is scattered across controllers instead of being centralized. No clear audit trail for "who can do what." Adding new permissions requires modifying controllers instead of policies.
- **Recommended refactor:** Create policies for major resources:
  - `UserPolicy` — for user management operations
  - `OrderPolicy` — for billing/order operations
  - `ServerPolicy` — refactor to use explicit methods
  - `NodePolicy` — for admin node operations
- **Suggested abstraction:** Individual policy classes per resource
- **Estimated effort:** Large
- **Risk of change:** High

---

## RV-2: Missing Authorization Checks on Admin Billing Controllers

- **Severity:** High
- **Category:** Security
- **Affected files:**
  - `app/Http/Controllers/Api/Application/Billing/ProductController.php`
  - `app/Http/Controllers/Api/Application/Billing/CouponController.php`
  - `app/Http/Controllers/Api/Application/Billing/CategoryController.php`
  - `app/Http/Controllers/Api/Application/Billing/OrderController.php`
  - Plus 6+ more admin controllers
- **Problem:** Admin billing controllers lack explicit `authorize()` calls. They rely on route-level middleware for authentication but may not validate specific admin permissions (e.g., can this admin manage billing vs only manage servers?).
- **Why it matters:** If middleware doesn't cover all permission checks, a lower-privilege admin could access billing operations.
- **Recommended refactor:** Add explicit authorization:
  ```php
  $this->authorize('manage-billing');
  // or
  $request->user()->hasPermission('billing.products.create');
  ```
- **Estimated effort:** Small
- **Risk of change:** Low

---

## RV-3: Inconsistent Request Validation Approaches — 199+ Files

- **Severity:** Medium
- **Category:** Consistency
- **Affected files:** `app/Http/Requests/` — 199+ request files
- **Problem:** Four different approaches to validation:
  1. **Model rules:** `public function rules(): array { return User::getRules(); }` (StoreUserRequest)
  2. **Model property:** `public function rules(): array { return Coupon::$validationRules; }` (StoreCouponRequest)
  3. **Inline rules:** Rules defined directly in the request class
  4. **Parent inheritance:** Store request extends Update request or vice versa

  No consistent pattern for which approach to use when.
- **Why it matters:** New developers must inspect each request class to understand the validation pattern. Rules for the same model may diverge between store and update requests.
- **Recommended refactor:** Standardize on model-based rules with request-specific overrides:
  ```php
  // In Model
  public static function validationRules(string $action = 'create'): array { ... }
  
  // In Request
  public function rules(): array {
      return User::validationRules('create');
  }
  ```
- **Estimated effort:** Large (199+ files)
- **Risk of change:** Medium

---

## RV-4: Repeated Permission Gating in Frontend

- **Severity:** Low
- **Category:** Duplicate Code
- **Affected files:** Multiple frontend components
- **Problem:** Permission checks in the frontend use inline string splitting and comparison:
  ```typescript
  const parts = permission.split('.');
  if (parts[0] === 'admin' && parts[1] === 'billing') { ... }
  ```
  This pattern is repeated in PermissionRow, EditSubuserModal, and 3+ more components.
- **Why it matters:** Permission format changes require updates in multiple places.
- **Recommended refactor:** Create `resources/scripts/lib/permissions.ts`:
  ```typescript
  export const hasPermission = (userPerms: string[], required: string): boolean => { ... }
  export const parsePermission = (perm: string): { scope: string, resource: string, action: string } => { ... }
  ```
- **Suggested abstraction:** `lib/permissions.ts`
- **Estimated effort:** Small
- **Risk of change:** Low

---

## RV-5: Rate Limiting Bypass via Direct Email Send

- **Severity:** Medium
- **Category:** Security
- **Affected files:**
  - `app/Jobs/Email/SendEmailJob.php` (line ~116) — checks rate limit
  - `app/Services/Email/EmailManager.php` — `send()` method — no rate limit
- **Problem:** Rate limiting is only checked in `SendEmailJob`. Direct calls to `EmailManager::send()` bypass rate limits entirely.
- **Why it matters:** Email abuse possible through custom email sends that don't go through the job queue.
- **Recommended refactor:** Add rate limiting in `EmailManager::send()` or ensure all sends go through the job.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## RV-6: Coupon Validation Race Condition

- **Severity:** Medium
- **Category:** Security / Business Logic
- **Affected files:**
  - `app/Services/Billing/BillingValidationService.php:140-192`
  - Checkout controllers
- **Problem:** Coupon validation happens during price calculation, but order creation is a separate step. Between validation and order creation, a coupon could be applied twice (race condition with concurrent requests).
- **Why it matters:** Revenue loss from double-applied coupons.
- **Recommended refactor:** Validate and apply coupon atomically within a database transaction:
  ```php
  DB::transaction(function () use ($couponCode, $order) {
      $coupon = Coupon::lockForUpdate()->where('code', $couponCode)->first();
      $this->validateCouponUsage($coupon, $order->user);
      $order->coupon_id = $coupon->id;
      $order->save();
      CouponUsage::create([...]);
  });
  ```
- **Estimated effort:** Medium
- **Risk of change:** Medium

---

## RV-7: Ownership Checks May Be Missing in Some Endpoints

- **Severity:** Medium
- **Category:** Security
- **Affected files:**
  - `app/Http/Controllers/Api/Client/Billing/CheckoutController.php`
  - Various client controllers
- **Problem:** Some endpoints use `$request->user()->id` to scope queries, but not all endpoints consistently verify ownership. The order lookup using `.latest()` could theoretically return a different user's order in edge cases (see HP-2 in high-priority findings).
- **Why it matters:** Potential cross-user data access.
- **Recommended refactor:** Audit all client endpoints to ensure `where('user_id', $request->user()->id)` is consistently applied. Consider using a model scope: `Order::forUser($user)`.
- **Estimated effort:** Medium
- **Risk of change:** Low

---

## RV-8: Path Traversal in Modpack Extraction

- **Severity:** High
- **Category:** Security
- **Affected files:** `app/Http/Controllers/Api/Client/Servers/ModsController.php` (lines ~558-602)
- **Problem:** ZIP entry paths are constructed before validation. Multiple string replacement steps for normalization could miss edge cases. `realpath()` only applied to temp directory, not individual extracted paths.
- **Why it matters:** Malicious ZIP files could write outside the intended directory.
- **Recommended refactor:** Validate paths before construction. Use `realpath()` on each extracted path. Consider using a library for safe ZIP extraction.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## RV-9: Secret Key Detection Logs but Continues

- **Severity:** High
- **Category:** Security
- **Affected files:** `app/Http/Controllers/Api/Client/Billing/CheckoutController.php:167-175`
- **Problem:** Detects Stripe secret key in publishable key field, logs critical message, but continues with the checkout process.
- **Why it matters:** A misconfigured secret key in client-side code would be exposed to users.
- **Recommended refactor:** Throw an exception or return an error response immediately.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## RV-10: Missing Transaction Boundaries

- **Severity:** Medium
- **Category:** Reliability
- **Affected files:** Multiple services that create/modify related records
- **Problem:** Only 4 uses of `DB::transaction` across 86+ services. Operations like order creation + server provisioning, user creation + role assignment, and bulk operations lack transactional protection.
- **Why it matters:** Partial data corruption if operations fail midway.
- **Recommended refactor:** Wrap multi-step operations in transactions. Document which services are transactional.
- **Estimated effort:** Medium
- **Risk of change:** Medium

---

## RV-11: API Key Logging Risk

- **Severity:** Low
- **Category:** Security
- **Affected files:** `app/Services/Plugins/PluginInstallService.php` (lines ~385-398)
- **Problem:** URL sanitization for logging doesn't strip query string parameters. If a provider embeds API keys in URLs, they could be logged.
- **Why it matters:** API key exposure in log files.
- **Recommended refactor:** Explicitly omit query string from sanitized URLs.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## RV-12: Temporary File Cleanup Not Guaranteed

- **Severity:** Low
- **Category:** Security / Reliability
- **Affected files:**
  - `app/Services/Plugins/PluginInstallService.php` — uses `@unlink()` (suppressed)
  - `app/Http/Controllers/Api/Client/Servers/ModsController.php` — temp dir creation without existence check
- **Problem:** Silently suppressed file deletion errors. `uniqid()` for temp names has theoretical collision risk. Failed cleanup could leave sensitive downloaded files on disk.
- **Why it matters:** Disk space leaks and potential data exposure.
- **Recommended refactor:** Log cleanup failures. Verify temp directory doesn't exist before creation. Use `finally` blocks consistently.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## RV-13: Webhook Idempotency Gaps

- **Severity:** Medium
- **Category:** Security / Reliability
- **Affected files:**
  - `app/Http/Controllers/Api/Client/Billing/MollieCheckoutController.php` (webhook handler)
  - `app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php` (capture handler)
- **Problem:** Webhook handlers check order status but don't acquire database locks. Concurrent webhook deliveries could process the same order simultaneously.
- **Why it matters:** Duplicate server provisioning or double-charging.
- **Recommended refactor:** Use `lockForUpdate()` in transaction:
  ```php
  DB::transaction(function () {
      $order = Order::where('id', $orderId)->lockForUpdate()->first();
      if ($order->status !== Order::STATUS_PENDING) return;
      // Process...
  });
  ```
- **Estimated effort:** Small
- **Risk of change:** Low

---

## RV-14: Weak Egg Validation in Billing

- **Severity:** Low
- **Category:** Validation
- **Affected files:** `app/Services/Billing/BillingValidationService.php:103-117`
- **Problem:** Validates that a requested egg is in the allowed list, but doesn't check that `getDefaultEggId()` returns a valid egg when falling back.
- **Why it matters:** Could create a server with a non-existent egg configuration.
- **Recommended refactor:** Validate the default egg exists before using it as a fallback.
- **Estimated effort:** Small
- **Risk of change:** Low
