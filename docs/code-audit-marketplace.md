# Code Audit — Marketplace / Billing

> Comprehensive audit of all billing, checkout, payment processor, and order management code.

---

## M-1: Three Duplicate Checkout Controllers — 60-80% Shared Code

- **Severity:** High
- **Category:** Duplicate Code / Architecture
- **Affected files:**
  - `app/Http/Controllers/Api/Client/Billing/CheckoutController.php` (479 lines — Stripe)
  - `app/Http/Controllers/Api/Client/Billing/MollieCheckoutController.php` (459 lines — Mollie)
  - `app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php` (674 lines — PayPal)
- **Problem:** All three controllers duplicate validation sequences, order creation, order type resolution, free-order processing, and email dispatch. The shared code accounts for 60-80% of each controller.
- **Why it matters:** Bug fixes must be applied 3 times. Feature additions require 3 parallel implementations. Divergence has already occurred (e.g., Stripe includes `billing_days` in order updates; Mollie and PayPal don't).
- **Recommended refactor:** Extract shared logic into a `CheckoutService`:
  - `validateCheckoutRequest()` — common validation calls
  - `createOrUpdateOrder()` — order creation/update
  - `processCompletedPayment()` — post-payment server creation
  - `handleFailedPayment()` — failure handling + email
  Each controller then only contains processor-specific payment creation/capture code.
- **Suggested abstraction:** `App\Services\Billing\CheckoutService`
- **Estimated effort:** Large
- **Risk of change:** Medium

---

## M-2: Duplicate `getOrderType()` — 3 Identical Methods

- **Severity:** Medium
- **Category:** Duplicate Code
- **Affected files:**
  - `app/Http/Controllers/Api/Client/Billing/CheckoutController.php:471-478`
  - `app/Http/Controllers/Api/Client/Billing/MollieCheckoutController.php:314-321`
  - `app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php:475-482`
- **Problem:** Identical private method in all 3 controllers:
  ```php
  private function getOrderType(Request $request): string {
      if ($request->has('renewal') && $request->boolean('renewal')) {
          return Order::TYPE_REN;
      }
      return Order::TYPE_NEW;
  }
  ```
- **Why it matters:** Classic copy-paste duplication.
- **Recommended refactor:** Move to a `CheckoutTrait` or shared `CheckoutService` method.
- **Suggested abstraction:** `App\Http\Controllers\Api\Client\Billing\Traits\CheckoutHelpers`
- **Estimated effort:** Small
- **Risk of change:** Low

---

## M-3: Duplicate `dispatchPaymentFailedEmail()` — 2 Identical Methods

- **Severity:** Medium
- **Category:** Duplicate Code
- **Affected files:**
  - `app/Http/Controllers/Api/Client/Billing/MollieCheckoutController.php:429-458`
  - `app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php:644-673`
- **Problem:** 100% identical implementation of payment failure email dispatch logic (30 lines). Both create email events with the same payload structure.
- **Why it matters:** Email logic changes require updating both files.
- **Recommended refactor:** Extract to shared service or trait.
- **Suggested abstraction:** `App\Services\Billing\PaymentNotificationService`
- **Estimated effort:** Small
- **Risk of change:** Low

---

## M-4: Validation Pattern Repeated 4+ Times

- **Severity:** Medium
- **Category:** Duplicate Code
- **Affected files:**
  - `app/Http/Controllers/Api/Client/Billing/CheckoutController.php` (processFree:75-92, updateIntent:287-307)
  - `app/Http/Controllers/Api/Client/Billing/MollieCheckoutController.php` (updatePayment:162-171)
  - `app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php` (updateOrder:168-177)
- **Problem:** Each checkout controller repeats the same 4-5 validation calls:
  ```php
  $this->validationService->validateNodeSelectionForProduct($nodeId, $product);
  $this->validationService->validateNodeDeployment($nodeId, true);
  $this->validationService->validateAndGetEggId($product, $requestedEggId);
  ```
- **Why it matters:** Adding a new validation step requires updating 4+ places.
- **Recommended refactor:** Create `CheckoutValidationService::validateCheckout()` that runs all validation in one call.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## M-5: Order Update Pattern Repeated 3 Times

- **Severity:** Medium
- **Category:** Duplicate Code
- **Affected files:**
  - `app/Http/Controllers/Api/Client/Billing/MollieCheckoutController.php:185-193`
  - `app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php:191-199`
  - `app/Http/Controllers/Api/Client/Billing/CheckoutController.php:343-355`
- **Problem:** Near-identical order update arrays in all 3 controllers. Stripe version slightly differs (includes `billing_days`).
- **Why it matters:** The slight divergence is already a potential bug source — were `billing_days` intentionally omitted from Mollie/PayPal?
- **Recommended refactor:** Create `Order::updateFromCheckout()` method or service method.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## M-6: No Payment Processor Interface

- **Severity:** High
- **Category:** Architecture
- **Affected files:**
  - `app/Http/Controllers/Api/Client/Billing/CheckoutController.php` — uses `StripeClient` directly
  - `app/Services/Billing/MolliePaymentService.php` — dedicated Mollie service
  - `app/Services/Billing/PayPalPaymentService.php` — dedicated PayPal service
- **Problem:** No common interface for payment processors. Stripe uses direct API calls in the controller while Mollie and PayPal have dedicated services with different interfaces.
- **Why it matters:** Adding a new payment processor requires rewriting the entire checkout flow from scratch.
- **Recommended refactor:** Create `PaymentProcessorInterface`:
  ```php
  interface PaymentProcessorInterface {
      public function createPayment(Order $order, array $options): PaymentResult;
      public function capturePayment(string $paymentId): PaymentResult;
      public function getPaymentStatus(string $paymentId): PaymentStatus;
      public function handleWebhook(Request $request): WebhookResult;
  }
  ```
- **Suggested abstraction:** `App\Contracts\PaymentProcessorInterface`
- **Estimated effort:** Large
- **Risk of change:** High

---

## M-7: Ambiguous Price Fields in Order Model

- **Severity:** High
- **Category:** Architecture / Data Integrity
- **Affected files:** `app/Models/Billing/Order.php`, `app/Services/Billing/CreateOrderService.php`
- **Problem:** Order model has 4 price fields with unclear semantics:
  - `total` — set to final amount
  - `final_price` — also set to final amount (same as `total`)
  - `subtotal` — before discount
  - `discount` — discount amount
  - `paypal_amount` — PayPal-specific (redundant with `total`)
  
  In `CreateOrderService:49-51`, both `total` and `final_price` are set to the same value.
- **Why it matters:** Accounting confusion. Reports may double-count or use the wrong field.
- **Recommended refactor:** Consolidate fields. Remove `final_price` (or `total`), remove `paypal_amount`. Add database check constraint: `total = subtotal - discount`.
- **Estimated effort:** Medium
- **Risk of change:** High

---

## M-8: Payment-Processor-Specific Fields Scattered in Order Table

- **Severity:** Medium
- **Category:** Architecture
- **Affected files:** `app/Models/Billing/Order.php`
- **Problem:** 10+ processor-specific columns: `payment_intent_id` (Stripe), `mollie_payment_id`, and 8 PayPal columns (`paypal_order_id`, `paypal_capture_id`, `paypal_payer_id`, `paypal_payer_email`, `paypal_status`, `paypal_amount`, `paypal_currency`, `paypal_captured_at`).
- **Why it matters:** Table bloat with many NULL columns. Adding a new processor means adding more columns.
- **Recommended refactor:** Use polymorphic `payment_details` table or JSON metadata column.
- **Estimated effort:** Large
- **Risk of change:** High

---

## M-9: Race Condition in Order Lookups

- **Severity:** Critical
- **Category:** Security
- **Affected files:** `app/Http/Controllers/Api/Client/Billing/CheckoutController.php` (lines ~389, 443, 451)
- **Problem:** Uses `Order::where('user_id', ...)->latest()->first()` which could pick the wrong order in race conditions. No verification that the payment intent belongs to the retrieved order.
- **Why it matters:** Potential cross-order payment association. Duplicate processing risk.
- **Recommended refactor:** Use explicit `payment_intent_id` lookup with user ownership verification.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## M-10: Webhook Not Fully Idempotent

- **Severity:** Medium
- **Category:** Security / Reliability
- **Affected files:**
  - `app/Http/Controllers/Api/Client/Billing/MollieCheckoutController.php:228-234`
  - `app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php`
- **Problem:** Webhook handlers check if order is already processed but don't acquire a database lock. Concurrent webhook deliveries can both process the same order.
- **Why it matters:** Duplicate server provisioning or double-charging.
- **Recommended refactor:** Add `lockForUpdate()` within a transaction. Return early if already in final state.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## M-11: Insufficient Coupon Validation Timing

- **Severity:** Medium
- **Category:** Security / Business Logic
- **Affected files:**
  - `app/Services/Billing/BillingValidationService.php:140-192`
  - `app/Http/Controllers/Api/Client/Billing/CheckoutController.php`
- **Problem:** Coupon validation happens during price calculation but order creation happens later. A user could apply a coupon twice between validation and final order creation (race condition).
- **Why it matters:** Revenue loss from double-applied coupons.
- **Recommended refactor:** Validate coupons atomically with order creation inside a database transaction.
- **Estimated effort:** Medium
- **Risk of change:** Medium

---

## M-12: Unused Order Type Constant

- **Severity:** Low
- **Category:** Dead Code
- **Affected files:** `app/Models/Billing/Order.php`
- **Problem:** `Order::TYPE_UPG = 'upg'` (upgrade) is defined but never used anywhere in the codebase.
- **Why it matters:** Confuses developers about the system's capabilities.
- **Recommended refactor:** Remove if upgrade functionality is not planned.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## M-13: Legacy Billing Processor Config

- **Severity:** Low
- **Category:** Dead Code / Clarity
- **Affected files:**
  - `config/modules/billing.php:14-15` — deprecated processor config
  - `app/Services/Billing/PaymentProcessorConfigService.php:17-19` — fallback reference
- **Problem:** Config says `// NOTE: This is deprecated. Payment processors are now managed via the integrations system...` but `PaymentProcessorConfigService` still reads it as a fallback.
- **Why it matters:** Config confusion for developers.
- **Recommended refactor:** Remove deprecated config or complete the migration to the integrations system.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## M-14: Secret Key Detection Logs but Continues

- **Severity:** High
- **Category:** Security
- **Affected files:** `app/Http/Controllers/Api/Client/Billing/CheckoutController.php:167-175`
- **Problem:** Detects Stripe secret key in publishable key field, logs a critical message, but continues processing. Should halt.
- **Why it matters:** Secret key in client-side code is a severe security breach.
- **Recommended refactor:** Throw an exception immediately after detection.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## M-15: Inconsistent Error Handling Across Billing

- **Severity:** Medium
- **Category:** Consistency
- **Affected files:**
  - `app/Services/Billing/CreateServerService.php:120` — throws `BillingExceptionClass`
  - `app/Services/Billing/BillingValidationService.php:38` — throws `DisplayException`
  - `app/Http/Controllers/Api/Client/Billing/CheckoutController.php:68` — throws `DisplayException`
- **Problem:** Three different exception types (`BillingExceptionClass`, `DisplayException`, generic `\Exception`) thrown without a clear strategy for when to use which.
- **Why it matters:** Inconsistent error handling makes it hard to catch and handle errors predictably.
- **Recommended refactor:** Define a clear exception strategy: `DisplayException` for user-facing errors, `BillingException` for logged billing errors, never generic `\Exception` for business logic.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## M-16: NodeAvailability Silent Failures

- **Severity:** Medium
- **Category:** Reliability
- **Affected files:** `app/Services/Billing/NodeAvailabilityService.php:32-36`
- **Problem:** Silently skips nodes that are unreachable (`continue` in catch block). Returns empty list if all nodes are offline, but callers can't distinguish between "no nodes available" and "all nodes offline."
- **Why it matters:** Users may see "no available nodes" when the real issue is network connectivity.
- **Recommended refactor:** Track and report the reason for unavailability. Add distinct error for "all nodes offline."
- **Estimated effort:** Small
- **Risk of change:** Low

---

## M-17: Duplicate Order Controllers (Application vs Client)

- **Severity:** Medium
- **Category:** Duplicate Code
- **Affected files:**
  - `app/Http/Controllers/Api/Application/Billing/OrderController.php:27-67`
  - `app/Http/Controllers/Api/Client/Billing/OrderController.php:24-63`
- **Problem:** 90% identical code with same query builder filters, sorts, and callbacks. Only difference is user ownership filter in the client version.
- **Why it matters:** Any filter or sort change must be applied to both files.
- **Recommended refactor:** Create `OrderQueryService` that builds the base query, with optional user filtering.
- **Suggested abstraction:** `App\Services\Billing\OrderQueryService`
- **Estimated effort:** Small
- **Risk of change:** Low

---

## M-18: Frontend Payment Form Duplication

- **Severity:** Low
- **Category:** Duplicate Code
- **Affected files:**
  - `resources/scripts/components/server/billing/PaymentForm.tsx`
  - `resources/scripts/components/account/billing/order/PaymentButton.tsx`
  - `resources/scripts/components/server/billing/MolliePaymentForm.tsx`
  - `resources/scripts/components/account/billing/order/MolliePaymentButton.tsx`
  - `resources/scripts/components/account/billing/order/PaymentMethodSelector.tsx`
  - `resources/scripts/components/server/billing/PaymentContainer.tsx`
- **Problem:** Both account and server have duplicate payment method selector logic and "No Payment Methods Available" UI.
- **Why it matters:** UI inconsistency if one is updated but not the other.
- **Recommended refactor:** Create shared `PaymentMethodSelector` component used by both account and server billing.
- **Estimated effort:** Small
- **Risk of change:** Low
