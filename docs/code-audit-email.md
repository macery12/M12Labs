# Code Audit — Email System

> Comprehensive audit of all email-related code including services, controllers, models, jobs, listeners, and frontend components.

---

## E-1: Duplicate Subject Mapping in Two Files

- **Severity:** High
- **Category:** Duplicate Code
- **Affected files:**
  - `app/Services/Email/EmailManager.php` — `getSubjectForTemplate()` (lines ~438-460)
  - `app/Jobs/Email/SendEmailJob.php` — `getSubjectForTemplate()` (lines ~205-226)
- **Problem:** Both files contain identical `getSubjectForTemplate()` methods with 22 lines of hardcoded subject-to-template mappings. Values differ subtly: EmailManager uses `'Welcome to Your Account'` while SendEmailJob uses `'Welcome to ' . config('app.name')`.
- **Why it matters:** Test emails and template-based emails produce different subjects. Any new template type requires updating two files.
- **Recommended refactor:** Extract to `EmailTypeRegistry::getSubjectForTemplate()` or a dedicated config array. Use a single method everywhere.
- **Suggested abstraction:** `EmailTypeRegistry` method or config constant
- **Estimated effort:** Small
- **Risk of change:** Low

---

## E-2: Duplicate Payload Sanitization

- **Severity:** High
- **Category:** Duplicate Code
- **Affected files:**
  - `app/Services/Email/EmailDeliveryTracker.php` — `sanitizePayload()` (lines ~242-261)
  - `app/Models/EmailDeliveryAttempt.php` — `sanitizePayload()` (lines ~91-110)
- **Problem:** Near-identical 20-line methods redacting sensitive keys (`api_key, token, password, secret, authorization`). `EmailDeliveryTracker` additionally checks `apiKey`. Both recursively sanitize nested arrays.
- **Why it matters:** If a new sensitive key needs redacting, one file may be missed. Security-critical code should have a single source of truth.
- **Recommended refactor:** Create shared `PayloadSanitizer` utility class.
- **Suggested abstraction:** `App\Services\Email\PayloadSanitizer`
- **Estimated effort:** Small
- **Risk of change:** Low

---

## E-3: Massive `sendFromTemplate()` Method — 253 Lines

- **Severity:** High
- **Category:** Architecture / Maintainability
- **Affected files:** `app/Services/Email/EmailManager.php` — `sendFromTemplate()` (lines ~117-370)
- **Problem:** Single method with 10+ responsibilities: create delivery record, validate template enablement, check email system enabled, load API settings (4x separate calls), validate from_email format, convert template key to view path, render template, generate text version, create tags, build message, track attempts, send via Resend, handle results. Contains 3 levels of nested try-catch blocks.
- **Why it matters:** Impossible to unit test individual responsibilities. High cognitive load for developers. Any change risks breaking unrelated functionality.
- **Recommended refactor:** Break into methods:
  - `resolveDeliveryRecord()`
  - `loadEmailConfig()` (returns DTO)
  - `renderTemplate()`
  - `buildEmailMessage()`
  - `dispatchEmail()`
  - `handleSendResult()`
- **Estimated effort:** Medium
- **Risk of change:** Medium

---

## E-4: Repeated Settings Loading — No Caching/DTO

- **Severity:** Medium
- **Category:** Performance / Duplicate Code
- **Affected files:**
  - `app/Services/Email/EmailManager.php` — `send()` (4 Setting::get calls at lines ~40, 47-49) and `sendFromTemplate()` (4 more at lines ~201, 213-215)
  - `app/Services/Email/EmailVerificationGate.php` — `shouldEnforce()` (line ~158)
  - `app/Http/Controllers/Api/Application/EmailController.php` — `getSettings()` (lines ~36-41)
- **Problem:** Email settings (`api_key`, `from_email`, `from_name`, `reply_to`) are loaded via separate `Setting::get()` calls every time an email is sent, with no caching between calls within the same request.
- **Why it matters:** Multiple database queries per email send. Inconsistent if settings change mid-request. Repetitive code pattern across 5+ files.
- **Recommended refactor:** Create `EmailConfigManager` or `EmailSettings` DTO that loads all settings once per request and provides type-safe access.
- **Suggested abstraction:** `App\Services\Email\EmailConfigManager`
- **Estimated effort:** Medium
- **Risk of change:** Low

---

## E-5: Duplicate Template-Enabled Check

- **Severity:** Medium
- **Category:** Duplicate Code
- **Affected files:**
  - `app/Jobs/Email/SendEmailJob.php` (line ~102)
  - `app/Services/Email/EmailManager.php` (line ~170)
- **Problem:** Both independently check if a template is enabled via `EmailNotificationSetting::isEnabled()`. The job checks before dispatching to the manager, and the manager checks again when processing.
- **Why it matters:** Redundant checks waste processing time and create maintenance burden. If the check logic changes, both must be updated.
- **Recommended refactor:** Keep the check only in `EmailManager::sendFromTemplate()` (the authoritative sender) and remove the duplicate in `SendEmailJob`.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## E-6: `EmailResult` Contract Violation

- **Severity:** Medium
- **Category:** Bug-Prone / Consistency
- **Affected files:** `app/Services/Email/EmailManager.php` (lines ~181, 197)
- **Problem:** `EmailResult::success()` expects a `messageId` string parameter, but is called with reason strings like `'disabled'` when the email system or template is disabled. This violates the type contract and may confuse downstream consumers expecting a valid message ID.
- **Why it matters:** Code consuming `EmailResult` may try to use the "messageId" for tracking, getting a reason string instead.
- **Recommended refactor:** Use `EmailResult::skipped()` (if it exists) or add a new static constructor for skip scenarios: `EmailResult::skipped(string $reason)`.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## E-7: Inconsistent Exception Types

- **Severity:** Medium
- **Category:** Consistency
- **Affected files:**
  - `app/Jobs/Email/SendEmailJob.php` (lines ~160, 175) — throws generic `\Exception`
  - `app/Services/Email/EmailManager.php` — mixed exception types
- **Problem:** Email code throws generic `\Exception` in some places and domain-specific exceptions in others. No consistent strategy for which exception type to use.
- **Why it matters:** Exception handlers cannot reliably distinguish between email failures and unexpected errors.
- **Recommended refactor:** Create `EmailSendingException` for email-specific failures. Use it consistently across all email services and jobs.
- **Suggested abstraction:** `App\Exceptions\EmailSendingException`
- **Estimated effort:** Small
- **Risk of change:** Low

---

## E-8: Large `extractDataFromEvent()` — 140 Lines, 13 If-Elseif

- **Severity:** Medium
- **Category:** Architecture
- **Affected files:** `app/Services/Email/EmailTypeRegistry.php` — `extractDataFromEvent()` (lines ~129-268)
- **Problem:** 140-line method with 13 chained if-elseif statements, one per email event type. Adding a new email type requires editing this massive method.
- **Why it matters:** Violates Open-Closed Principle. High risk of introducing bugs when adding new event types.
- **Recommended refactor:** Use strategy pattern — each email type registers its own data extractor. Or use a map of event class → extractor callable.
- **Estimated effort:** Medium
- **Risk of change:** Medium

---

## E-9: Missing Email Validation in EmailMessage

- **Severity:** Medium
- **Category:** Validation
- **Affected files:** `app/Services/Email/EmailMessage.php`
- **Problem:** `EmailMessage` only throws when `from` is missing (line ~74). It does not validate that `$to` is a valid email, `$subject` is not empty, or `$html` is not empty. Invalid messages are only caught at the API call level.
- **Why it matters:** Fail-fast validation would provide clearer error messages and prevent wasted API calls.
- **Recommended refactor:** Add constructor validation for required fields.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## E-10: Rate Limiting Bypass via Direct `send()` Method

- **Severity:** Medium
- **Category:** Security
- **Affected files:**
  - `app/Jobs/Email/SendEmailJob.php` (line ~116) — checks rate limit
  - `app/Services/Email/EmailManager.php` — `send()` method (line ~21) — no rate limit
- **Problem:** Rate limiting is only checked in `SendEmailJob` when a `user_id` exists. Direct calls to `EmailManager::send()` bypass rate limiting entirely.
- **Why it matters:** Custom email sends via controller could bypass quotas, potentially allowing email abuse.
- **Recommended refactor:** Add rate limiting in `EmailManager::send()` or ensure all email sending goes through the job.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## E-11: Inconsistent Log Levels

- **Severity:** Low
- **Category:** Consistency
- **Affected files:**
  - `app/Services/Email/EmailDeliveryTracker.php` — uses `Log::debug()` (line ~36) for "Starting delivery" but `Log::info()` (line ~65) for "Marking deferred"
  - `app/Services/Email/ResendHttpClient.php` — generic error logging without context (line ~107)
- **Problem:** Important tracking events use `debug` level, while less critical events use `info`. No correlation ID in all log statements.
- **Why it matters:** Production log filtering may miss important events. Tracing email delivery issues across services is difficult.
- **Recommended refactor:** Standardize log levels: `info` for tracking/audit events, `warning` for retries, `error` for failures. Include correlation ID in all email logs.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## E-12: `EmailQuota::OVERAGE_COST_PER_1000` — Unused

- **Severity:** Low
- **Category:** Dead Code
- **Affected files:** `app/Models/EmailQuota.php` (line ~72)
- **Problem:** Constant `OVERAGE_COST_PER_1000` is defined and referenced in `getRemainingQuota()` but never used to actually charge or enforce overage fees.
- **Why it matters:** Dead code confuses developers about the system's capabilities.
- **Recommended refactor:** Remove if overage billing is not planned. If planned, implement or add a TODO with context.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## E-13: Hardcoded Retry Count in ResendHttpClient

- **Severity:** Low
- **Category:** Maintainability
- **Affected files:** `app/Services/Email/ResendHttpClient.php` (line ~18)
- **Problem:** `MAX_RETRIES = 3` is hardcoded. Not configurable via environment or settings.
- **Why it matters:** Different deployments may need different retry strategies.
- **Recommended refactor:** Make configurable via config or constructor parameter.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## E-14: Frontend — Complex Manual Debounce in ResendSettings

- **Severity:** Low
- **Category:** Maintainability
- **Affected files:** `resources/scripts/components/admin/modules/email/ResendSettings.tsx` (lines ~92-117)
- **Problem:** Manual debounce implementation using refs and timeouts. Nine separate state variables (lines ~20-32) could use a reducer. API key is cleared after save (`setApiKey('')`), which is good for security but bad UX if the save fails.
- **Why it matters:** Fragile state management prone to bugs. Complex debounce logic that could use a library.
- **Recommended refactor:** Use `useDebouncedCallback` hook from a library. Consider `useReducer` for related state.
- **Estimated effort:** Small
- **Risk of change:** Low

---

## E-15: Frontend — Missing Loading Skeleton in EmailActivityLog

- **Severity:** Low
- **Category:** UX / Consistency
- **Affected files:** `resources/scripts/components/admin/modules/email/EmailActivityLog.tsx`
- **Problem:** Shows a spinner only after data is fetched, not during initial load. Manual debounce with ref pattern (lines ~115-127) is fragile.
- **Why it matters:** Inconsistent loading state UX compared to other components.
- **Recommended refactor:** Add loading skeleton for initial load. Use consistent debounce pattern.
- **Estimated effort:** Small
- **Risk of change:** Low
