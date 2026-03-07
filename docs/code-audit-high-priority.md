# Code Audit — High-Priority Findings

> Only high-severity issues, bug-prone duplication, major security/permission concerns, and major architectural problems.

---

## HP-1: Debug Code in Production — `dd()` in RoleController

- **Severity:** Critical
- **Category:** Bug / Dead Code
- **Location:** `app/Http/Controllers/Api/Application/Roles/RoleController.php:105`
- **Problem:** The `updatePermissions()` method contains a `dd()` (dump-and-die) call that will terminate the request and dump output. The code after `dd()` is dead code and never executes. The `PATCH /api/application/roles/{id}/permissions` route is completely broken.
- **Why it matters:** Any admin attempting to update role permissions will see a raw dump instead of a proper response. This is a broken production feature.
- **Recommended refactor:** Remove the `dd()` call and implement the actual permission update logic, or remove the route if the feature is incomplete.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## HP-2: Race Condition — Loose Order Lookups in CheckoutController

- **Severity:** Critical
- **Category:** Security / Bug
- **Location:** `app/Http/Controllers/Api/Client/Billing/CheckoutController.php` (lines ~389, 443, 451)
- **Problem:** Uses `Order::where('user_id', ...)->latest()->first()` to find orders. The `latest()` scope without a column defaults to `created_at`, and in high-load or race conditions, this could pick the wrong order. Additionally, no check that the Stripe payment intent belongs to the retrieved order.
- **Why it matters:** An attacker could theoretically provide their own order ID and someone else's payment intent. Duplicate server creation is possible if webhook and user both trigger simultaneously.
- **Recommended refactor:** Replace all 3 instances with explicit `payment_intent_id` lookup: `Order::where('payment_intent_id', $request->input('intent'))->where('user_id', ...)->firstOrFail()`
- **Estimated effort:** Small
- **Risk of change:** Low

---

## HP-3: Incomplete Idempotency — Duplicate Order Processing

- **Severity:** Critical
- **Category:** Security / Bug
- **Location:**
  - `app/Http/Controllers/Api/Client/Billing/CheckoutController.php` (processPaid method)
  - `app/Http/Controllers/Api/Client/Billing/MollieCheckoutController.php` (webhook handler)
- **Problem:** Order status is checked but no database lock is acquired before processing. If both a webhook and user callback arrive simultaneously, both could see the order as `PENDING` and process it, potentially creating duplicate servers or double-charging.
- **Why it matters:** Financial integrity — duplicate charges or server provisioning.
- **Recommended refactor:** Use optimistic locking (`$order->lockForUpdate()`) or database-level idempotency (unique constraint on processing state transitions) within a transaction.
- **Estimated effort:** Medium
- **Risk of change:** Medium

---

## HP-4: Path Traversal Risk in Modpack Extraction

- **Severity:** High
- **Category:** Security
- **Location:** `app/Http/Controllers/Api/Client/Servers/ModsController.php` (lines ~558-602)
- **Problem:** ZIP entry paths are constructed *before* being validated for traversal attacks. While `../` checks exist, the normalization approach (multiple `str_replace` and `preg_replace` calls) could miss edge cases. The `realpath()` is only called once on `$tempDir`, not on each extracted path.
- **Why it matters:** A malicious modpack ZIP could potentially write files outside the intended directory.
- **Recommended refactor:** Validate and normalize paths *before* constructing file paths. Use `realpath()` on each extracted path after creation to verify it remains within the target directory.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## HP-5: Three Duplicate Checkout Controllers

- **Severity:** High
- **Category:** Duplicate Code / Architecture
- **Location:**
  - `app/Http/Controllers/Api/Client/Billing/CheckoutController.php` (479 lines — Stripe)
  - `app/Http/Controllers/Api/Client/Billing/MollieCheckoutController.php` (459 lines — Mollie)
  - `app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php` (674 lines — PayPal)
- **Problem:** All three controllers duplicate: `getOrderType()` (identical method 3x), `dispatchPaymentFailedEmail()` (identical 2x), validation sequences (4+ repetitions), order update patterns (3x near-identical), and free-order processing. Combined, ~60-80% of the code is duplicated or near-duplicated across these files.
- **Why it matters:** Bug fixes or feature changes must be applied in 3 places. Divergence has already occurred (e.g., Stripe's order update includes `billing_days` while Mollie's and PayPal's don't).
- **Recommended refactor:** Create a `PaymentProcessorInterface` and extract shared checkout logic into a `CheckoutService`. Each controller should only contain processor-specific payment creation/capture code.
- **Suggested abstraction:** `CheckoutService`, `PaymentProcessorInterface`, `CheckoutValidationService`
- **Estimated effort:** Large
- **Risk of change:** Medium

---

## HP-6: No Payment Processor Abstraction

- **Severity:** High
- **Category:** Architecture
- **Location:**
  - `app/Http/Controllers/Api/Client/Billing/CheckoutController.php` (Stripe — direct `StripeClient` calls)
  - `app/Services/Billing/MolliePaymentService.php` (Mollie — dedicated service)
  - `app/Services/Billing/PayPalPaymentService.php` (PayPal — dedicated service)
- **Problem:** Each payment processor is implemented with completely different patterns. Stripe uses direct API calls in the controller; Mollie and PayPal have dedicated services but with different interfaces. No common interface exists. Adding a new payment processor requires rewriting the entire checkout flow.
- **Why it matters:** Extensibility, maintainability, and testing are severely hampered.
- **Recommended refactor:** Create `PaymentProcessorInterface` with methods: `createPayment()`, `capturePayment()`, `getPaymentStatus()`, `handleWebhook()`.
- **Estimated effort:** Large
- **Risk of change:** High

---

## HP-7: Massive Controller Methods

- **Severity:** High
- **Category:** Architecture / Maintainability
- **Location:**
  - `app/Services/Email/EmailManager.php` — `sendFromTemplate()` is 253 lines with 3 nested try-catch levels
  - `app/Http/Controllers/Api/Client/Servers/ModsController.php` — `downloadModpack()` is 237 lines
  - `app/Jobs/Email/SendEmailJob.php` — `handle()` is 132 lines
  - `app/Services/Email/EmailTypeRegistry.php` — `extractDataFromEvent()` is 140 lines with 13 if-elseif chains
- **Problem:** Methods violate Single Responsibility Principle, are hard to test, hard to read, and hard to modify safely.
- **Why it matters:** Any change to these methods risks regressions, and unit testing individual responsibilities is impossible.
- **Recommended refactor:** Break into 5-7 smaller methods each. Use strategy pattern for `extractDataFromEvent()`. Extract modpack download into dedicated `ModpackInstallService`.
- **Estimated effort:** Medium per method
- **Risk of change:** Medium

---

## HP-8: Ambiguous Pricing Fields in Order Model

- **Severity:** High
- **Category:** Architecture / Data Integrity
- **Location:** `app/Models/Billing/Order.php`
- **Problem:** The Order model has 4 different price fields (`total`, `final_price`, `subtotal`, `discount`) plus `paypal_amount`, with unclear semantics. In `CreateOrderService`, both `total` and `final_price` are set to the same value. No database constraint ensures `total === subtotal - discount`.
- **Why it matters:** Accounting reports could double-count discounts or mix up fields, leading to revenue reporting errors.
- **Recommended refactor:** Consolidate to clear fields (`subtotal`, `discount_amount`, `total`) with a database check constraint. Remove `paypal_amount` (redundant with `total`). Add accessor methods that enforce the relationship.
- **Estimated effort:** Medium
- **Risk of change:** High (requires migration and data audit)

---

## HP-9: Payment-Processor-Specific Fields Scattered in Order Table

- **Severity:** High
- **Category:** Architecture
- **Location:** `app/Models/Billing/Order.php`
- **Problem:** The Order table contains 10+ payment-processor-specific columns: `payment_intent_id` (Stripe), `mollie_payment_id` (Mollie), and 8 PayPal-specific columns. This violates schema normalization and makes adding new processors require schema changes.
- **Why it matters:** Table bloat, NULL-heavy rows, and tight coupling between the order model and payment processors.
- **Recommended refactor:** Use a polymorphic `order_payments` table or JSON metadata column for processor-specific data.
- **Estimated effort:** Large
- **Risk of change:** High

---

## HP-10: Duplicate ActivityLog Transformers — 150 Lines

- **Severity:** High
- **Category:** Duplicate Code
- **Location:**
  - `app/Transformers/Api/Application/ActivityLogTransformer.php` (210 lines)
  - `app/Transformers/Api/Client/ActivityLogTransformer.php` (220 lines)
- **Problem:** 90%+ of the code is identical: `includeActor()`, `properties()`, `hasAdditionalMetadata()`, `scope()`, `inferScope()`, `context()`, `category()`, `source()`, `severity()` methods. Only difference: Client version has additional `canViewIP()` method and slightly different timestamp formatting.
- **Why it matters:** Any bug fix or feature addition must be made in both files.
- **Recommended refactor:** Create `BaseActivityLogTransformer` with shared methods; extend in Application and Client versions.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## HP-11: Duplicate Template-Enabled Check

- **Severity:** High
- **Category:** Duplicate Code / Bug-Prone
- **Location:**
  - `app/Jobs/Email/SendEmailJob.php` (line ~102)
  - `app/Services/Email/EmailManager.php` (line ~170)
- **Problem:** Both `SendEmailJob::handle()` and `EmailManager::sendFromTemplate()` independently check whether a template is enabled. If one is patched (e.g., new skip reason added), the other may diverge, leading to inconsistent behavior.
- **Why it matters:** Emails could be sent that should be blocked, or vice versa, depending on which code path is taken.
- **Recommended refactor:** Keep the check in only one place — either the job (before dispatching to the manager) or the manager (which is the authoritative sender). Remove the duplicate.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## HP-12: Duplicate Email Subject Mapping — Inconsistent Values

- **Severity:** High
- **Category:** Duplicate Code / Bug
- **Location:**
  - `app/Services/Email/EmailManager.php` — `getSubjectForTemplate()` (lines ~438-460)
  - `app/Jobs/Email/SendEmailJob.php` — `getSubjectForTemplate()` (lines ~205-226)
- **Problem:** Both files contain identical `getSubjectForTemplate()` methods with hardcoded subject mappings. However, the values differ: EmailManager has `'Welcome to Your Account'` while SendEmailJob has `'Welcome to ' . config('app.name')`. This means test emails and template-based emails may have different subjects.
- **Why it matters:** Users receive inconsistent email subjects depending on the sending path.
- **Recommended refactor:** Extract to a single source of truth — either `EmailTypeRegistry` or a dedicated configuration array.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## HP-13: Missing Authorization Checks on Admin Billing Controllers

- **Severity:** High
- **Category:** Security
- **Location:** `app/Http/Controllers/Api/Application/Billing/` (ProductController, CouponController, CategoryController, OrderController)
- **Problem:** Admin billing controllers lack explicit `authorize()` calls for admin role verification. They rely on middleware for authentication but may not validate that the authenticated user has admin permissions for billing operations.
- **Why it matters:** Potential privilege escalation if a non-admin user can access admin billing endpoints.
- **Recommended refactor:** Add explicit policy or `authorize()` checks in each admin controller, or verify that middleware covers all routes.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## HP-14: Secret Key Detection Logs but Continues

- **Severity:** High
- **Category:** Security
- **Location:** `app/Http/Controllers/Api/Client/Billing/CheckoutController.php` (lines ~167-175)
- **Problem:** When a Stripe secret key is detected in the publishable key field, the code logs a critical message but continues processing. This should be a hard stop.
- **Why it matters:** A secret key exposed in client-side code is a severe security breach. Logging and continuing provides no protection.
- **Recommended refactor:** Throw an exception or return an error response immediately after detecting the misconfiguration.
- **Estimated effort:** Small
- **Risk of change:** Low
