# Code Audit — Refactor Roadmap

> Phased execution plan for addressing audit findings, ordered by value, risk, and dependency.

---

## Guiding Principles

1. **Safety first:** Start with changes that have low risk of regression
2. **High value first:** Prioritize changes that eliminate the most duplication or fix the most critical issues
3. **Dependencies:** Some changes unlock others — order accordingly
4. **Testing:** Each phase should include test coverage for changed code
5. **Incremental:** Ship each phase independently — don't batch everything into one massive PR

---

## Phase 1: Quick Wins — Safest High-Value Fixes

> **Goal:** Fix bugs, remove dead code, extract trivial duplicates  
> **Timeline:** 1-2 days  
> **Risk:** Low  
> **Dependencies:** None

### 1.1 Remove `dd()` from RoleController (BE-2)
- **File:** `app/Http/Controllers/Api/Application/Roles/RoleController.php:105`
- **Action:** Remove `dd()` call, implement actual permission update or remove the endpoint
- **Effort:** 5 minutes
- **Impact:** Unblocks broken production route

### 1.2 Extract `getOrderType()` to Trait (M-2)
- **Files:** 3 checkout controllers
- **Action:** Create `CheckoutHelpers` trait with `getOrderType()`, use in all 3 controllers
- **Effort:** 30 minutes
- **Impact:** Removes 3x code duplication

### 1.3 Extract `dispatchPaymentFailedEmail()` (M-3)
- **Files:** MollieCheckoutController, PayPalCheckoutController
- **Action:** Move to shared service or trait
- **Effort:** 30 minutes
- **Impact:** Removes email logic duplication

### 1.4 Fix Loose Order Lookups (HP-2)
- **Files:** `CheckoutController.php` (3 instances)
- **Action:** Replace `latest()->first()` with explicit `payment_intent_id` lookup
- **Effort:** 1 hour
- **Impact:** Prevents potential race conditions

### 1.5 Fix Secret Key Detection (HP-14)
- **File:** `CheckoutController.php`
- **Action:** Throw exception instead of just logging when secret key detected
- **Effort:** 15 minutes
- **Impact:** Prevents security misconfiguration from continuing

### 1.6 Centralize Email Subject Mapping (E-1)
- **Files:** `EmailManager.php`, `SendEmailJob.php`
- **Action:** Move `getSubjectForTemplate()` to `EmailTypeRegistry`, remove duplicates
- **Effort:** 1 hour
- **Impact:** Single source of truth for email subjects

### 1.7 Extract `sanitizePayload()` (E-2)
- **Files:** `EmailDeliveryTracker.php`, `EmailDeliveryAttempt.php`
- **Action:** Create `PayloadSanitizer` class
- **Effort:** 30 minutes
- **Impact:** Removes security-critical code duplication

### 1.8 Create Status Color Utility (FE-1)
- **Files:** 8+ frontend components
- **Action:** Create `lib/statusColors.ts`, update all switch statements
- **Effort:** 1 hour
- **Impact:** Removes 8x frontend duplication

### 1.9 Remove Dead Code
- **Files:** Various
- **Actions:**
  - Remove `Order::TYPE_UPG` if unused (M-12)
  - Remove `SpigetService::getMinecraftVersions()` and `getModLoaderTypes()` empty methods (PM-10)
  - Remove or implement unused `EmailQuota::OVERAGE_COST_PER_1000` (E-12)
  - Convert TODO comments to GitHub issues (BE-12)
- **Effort:** 1 hour
- **Impact:** Reduces codebase noise

---

## Phase 2: Shared Abstractions & Consolidation

> **Goal:** Extract shared services, traits, hooks, and base classes  
> **Timeline:** 3-5 days  
> **Risk:** Low-Medium  
> **Dependencies:** Phase 1 complete

### 2.1 Extract Pagination Validation to Base Controller (BE-1)
- **Files:** Base `ApplicationApiController` + 38 controllers
- **Action:** Add `getPaginationLimit()` method, replace all inline validation
- **Effort:** 2-3 hours
- **Impact:** Removes 150+ duplicate lines, standardizes defaults

### 2.2 Create Base ActivityLog Transformer (BE-3)
- **Files:** 2 transformer files
- **Action:** Extract `BaseActivityLogTransformer` abstract class, extend in Application/Client versions
- **Effort:** 2 hours
- **Impact:** Removes 150+ duplicate lines

### 2.3 Create ActivityLog Query Scopes (BE-4)
- **Files:** `ActivityLog` model + 3 controllers
- **Action:** Add `scopeAdminActivity()`, `scopeClientActivity()`, `scopeSearchable()` scopes
- **Effort:** 2 hours
- **Impact:** Removes 80+ duplicate lines of complex query logic

### 2.4 Extract HttpThrottler Trait (PM-2)
- **Files:** `CurseForgeService.php`, `ModrinthService.php`
- **Action:** Create `HttpThrottled` trait with shared `simpleThrottle()` method
- **Effort:** 1-2 hours
- **Impact:** Removes 70 duplicate lines

### 2.5 Extract CachedProviderClient Trait (PM-3)
- **Files:** `CurseForgeService.php`, `ModrinthService.php`
- **Action:** Create shared `CachedProviderClient` trait for `makeCachedRequest()`
- **Effort:** 1-2 hours
- **Impact:** Removes 95% duplicate caching logic

### 2.6 Create ErrorHandling Wrapper in ModsController (PM-4)
- **Files:** `ModsController.php`
- **Action:** Create `handleServiceCall()` private method, replace 8 identical try-catch blocks
- **Effort:** 1-2 hours
- **Impact:** Removes 100+ lines of repetitive error handling

### 2.7 Create `useFormSubmit` Hook (FE-2)
- **Files:** New hook + 60+ form components
- **Action:** Create hook that standardizes error handling, flash clearing, and submit state
- **Effort:** 4-6 hours (hook creation + migration of most-used forms)
- **Impact:** Standardizes form submission across 60+ components

### 2.8 Create `EmailConfigManager` DTO (E-4)
- **Files:** `EmailManager.php`, `EmailVerificationGate.php`, `EmailController.php`
- **Action:** Create DTO that loads all email settings once per request
- **Effort:** 2-3 hours
- **Impact:** Eliminates repeated settings loading

### 2.9 Create Status Constants (FE-4)
- **Files:** New constants file + 50+ components
- **Action:** Create `constants/statuses.ts` with typed status enums
- **Effort:** 2-3 hours
- **Impact:** Single source of truth for 50+ files

### 2.10 Create `LogsActivities` Trait (BE-5)
- **Files:** 30+ controllers
- **Action:** Create trait with `logCreation()`, `logUpdate()`, `logDeletion()` methods
- **Effort:** 3-4 hours
- **Impact:** Removes 200+ repetitive logging lines

### 2.11 Add Webhook Idempotency (RV-13)
- **Files:** MollieCheckoutController, PayPalCheckoutController
- **Action:** Add `lockForUpdate()` in transaction for webhook processing
- **Effort:** 1-2 hours
- **Impact:** Prevents duplicate server provisioning

### 2.12 Create OrderQueryService (BE-11, M-17)
- **Files:** 2 order controllers
- **Action:** Extract shared query builder to service
- **Effort:** 1-2 hours
- **Impact:** Removes 40+ duplicate lines

---

## Phase 3: Deeper Architecture Cleanup

> **Goal:** Address major architectural issues that require broader changes  
> **Timeline:** 1-2 weeks  
> **Risk:** Medium-High  
> **Dependencies:** Phases 1 and 2 complete

### 3.1 Extract Shared CheckoutService (M-1, HP-5)
- **Files:** 3 checkout controllers + new service
- **Action:** Create `CheckoutService` with shared validation, order creation, and post-payment logic. Each controller calls the shared service for common operations and handles only processor-specific logic.
- **Effort:** 2-3 days
- **Impact:** Reduces 3 controllers by 60-80%, prevents divergence
- **Dependency:** Phase 1 trait extractions (1.2, 1.3)

### 3.2 Create PaymentProcessorInterface (M-6, HP-6)
- **Files:** New interface + 3 service implementations
- **Action:** Define `PaymentProcessorInterface` and implement for Stripe, Mollie, PayPal
- **Effort:** 2-3 days
- **Impact:** Enables adding new processors without rewriting checkout
- **Dependency:** 3.1 (CheckoutService)

### 3.3 Refactor `EmailManager::sendFromTemplate()` (E-3)
- **Files:** `EmailManager.php`
- **Action:** Break 253-line method into 5-7 focused methods
- **Effort:** 1-2 days
- **Impact:** Testable, maintainable email sending pipeline

### 3.4 Split ModsController (BE-14, PM-7)
- **Files:** `ModsController.php` (1,029 lines) → 4 controllers
- **Action:** Split into `ModSearchController`, `ModInstallController`, `ModpackController`, `InstalledAddonsController`
- **Effort:** 1-2 days
- **Impact:** Each controller under 300 lines
- **Dependency:** Phase 2 error handling wrapper (2.6)

### 3.5 Remove Provider Adapter Anti-Pattern (PM-1)
- **Files:** 3 adapter files + `PluginInstallService`
- **Action:** Remove adapters, use factory method directly
- **Effort:** 1 day
- **Impact:** Removes unnecessary indirection layer

### 3.6 Extract ModpackInstallService (PM-7)
- **Files:** `ModsController.php` → new service
- **Action:** Extract `downloadModpack()` (237 lines) into dedicated service with components: `ModpackExtractor`, `ModpackModDownloader`, `ModpackInstaller`
- **Effort:** 1-2 days
- **Impact:** Testable modpack installation, safer security
- **Dependency:** 3.4 (ModsController split)

### 3.7 Fix Path Traversal (PM-8, RV-8)
- **Files:** `ModsController.php` or new `ModpackExtractor`
- **Action:** Validate before construction. Use `realpath()` per path.
- **Effort:** 1 day
- **Impact:** Eliminates security risk
- **Dependency:** 3.6 (extraction makes this safer to change)

### 3.8 Create `useModalState` Hook (FE-3)
- **Files:** New hook + 32 modal components
- **Action:** Standardize modal state management
- **Effort:** 2-3 days (hook + gradual migration)
- **Impact:** Consistent modal behavior across 32 components

### 3.9 Standardize Exception Hierarchy (BE-6, M-15)
- **Files:** Multiple services and controllers
- **Action:** Define exception strategy: `DisplayException` for user-facing, domain exceptions for internal, never generic `\Exception`. Create missing exception classes.
- **Effort:** 1-2 days
- **Impact:** Predictable error handling throughout the codebase

### 3.10 Consolidate Order Price Fields (M-7, HP-8)
- **Files:** `Order.php` + migration + services
- **Action:** Migrate `total`/`final_price` to single field. Remove `paypal_amount`. Add check constraint.
- **Effort:** 2-3 days
- **Impact:** Clear pricing semantics, accounting clarity
- **Risk:** High — requires data migration

### 3.11 Add Missing Transaction Boundaries (BE-7, RV-10)
- **Files:** Services that modify multiple records
- **Action:** Wrap order processing, user creation, server operations in transactions
- **Effort:** 2-3 days
- **Impact:** Data integrity protection

### 3.12 Refactor `extractDataFromEvent()` (E-8)
- **Files:** `EmailTypeRegistry.php`
- **Action:** Use strategy pattern — each email type registers its own data extractor
- **Effort:** 1-2 days
- **Impact:** Open-Closed Principle compliance

---

## Phase 4: Long-Term Architecture (Optional)

> **Goal:** Address structural issues that require significant refactoring  
> **Timeline:** 2-4 weeks  
> **Risk:** High  
> **Dependencies:** Phase 3 complete

### 4.1 Normalize Order Payment Details (M-8, HP-9)
- Extract processor-specific fields into polymorphic `payment_details` table
- Effort: 1 week (migration, model updates, controller updates)

### 4.2 Create Resource Policies (RV-1, BE-13)
- Create policies for User, Order, Node, Egg, etc.
- Centralize all authorization logic
- Effort: 1 week

### 4.3 Standardize Request Validation (RV-3, BE-10)
- Define and document one validation approach
- Migrate all 199+ request files
- Effort: 1-2 weeks

### 4.4 Frontend Custom Hooks Library
- Create comprehensive hooks library:
  - `useFormSubmit`, `useModalState`, `useTableFilters`
  - `useDebouncedSearch`, `useStatusColor`, `usePermissions`
- Migrate all components
- Effort: 1-2 weeks

### 4.5 Create InstallableItem Abstraction (Mods + Plugins)
- Unify mods, plugins, and modpacks under shared interface
- Common installation, update, removal flows
- Effort: 1-2 weeks

---

## Summary

| Phase | Items | Est. Effort | Risk | Key Wins |
|-------|-------|-------------|------|----------|
| **1: Quick Wins** | 9 | 1-2 days | Low | Fix bugs, remove dead code, extract trivial duplicates |
| **2: Consolidation** | 12 | 3-5 days | Low-Med | Shared abstractions, base classes, hooks |
| **3: Architecture** | 12 | 1-2 weeks | Med-High | Service extraction, controller splits, security fixes |
| **4: Long-Term** | 5 | 2-4 weeks | High | Schema normalization, full policy system, hooks library |

### Recommended Starting Order Within Phase 1
1. `dd()` removal (5 min)
2. Secret key detection fix (15 min)
3. `getOrderType()` trait (30 min)
4. `dispatchPaymentFailedEmail()` extraction (30 min)
5. `sanitizePayload()` extraction (30 min)
6. Loose order lookup fix (1 hr)
7. Email subject centralization (1 hr)
8. Status color utility (1 hr)
9. Dead code removal (1 hr)

### What Not to Do First
- Don't touch the Order schema until checkout is consolidated (Phase 3.10 depends on 3.1)
- Don't add policies before standardizing exceptions (3.9 before 4.2)
- Don't refactor ModsController before extracting error handlers (2.6 before 3.4)
- Don't create frontend hooks before defining status constants (2.9 before 3.8)
