# Progressive Email Logging System

## Overview

This document describes the progressive email logging system implemented to eliminate duplicate `EmailLog` entries. The new system ensures that each email send produces exactly **ONE** `EmailLog` row that is progressively updated as more information becomes available.

## Key Changes

### 1. Database Schema

**Migration**: `2026_02_16_200000_add_unique_constraints_and_status_code_to_email_logs.php`

- **Unique constraint on `correlation_id`**: Prevents duplicate logs during the early phase before `message_id` is known
- **Unique constraint on `(provider, message_id)`**: Prevents duplicate logs for the same provider message
- **New field `status_code`**: Stores HTTP status codes from provider validation errors (e.g., Resend 422)

### 2. EmailLog Model

**File**: `app/Models/EmailLog.php`

- Added `status_code` to fillable fields and casts
- Verified array casts for `tags`, `metadata`, `template_variables` (already present)

### 3. Progressive Logging Flow

#### Phase 1: Job Start (SendEmailJob)

**File**: `app/Jobs/Email/SendEmailJob.php`

When `SendEmailJob::handle()` starts:
1. Generates `correlation_id` if not provided (persists to `$this->correlationId`)
2. Creates initial log entry with `EmailLog::updateOrCreate()`:
   - `correlation_id`: Unique identifier for this send
   - `status`: `'processing'`
   - `template_key`: Email template being sent
   - `user_id`: User receiving the email
   - `recipient`: Email address
   - `subject`: Email subject
   - `attempt_count`: Current attempt number from `$this->attempts()`

#### Phase 2: Email Building (EmailManager)

**File**: `app/Services/Email/EmailManager.php`

Before sending via Resend:
1. Ensures `correlation_id` is set (auto-generates if null)
2. Updates log with rendered content:
   - `rendered_subject`: Final subject after template rendering
   - `rendered_html`: Complete HTML content
   - `rendered_text`: Plain text version
   - `tags`: Sanitized tags (ASCII-safe, provider-compatible)
   - `template_variables`: Template data (sensitive values redacted)

#### Phase 3: Send Result (EmailManager)

After Resend API call:
1. Updates log with provider response:
   - `message_id`: Provider's unique message identifier
   - `success`: Boolean success flag
   - `status`: `'sent'` or `'failed'`
   - `error`: Error message if failed
   - `status_code`: HTTP status code from provider
   - `duration_ms`: Time taken to send (milliseconds)

#### Phase 4: Job Failure (SendEmailJob)

If job fails permanently:
1. `SendEmailJob::failed()` updates log:
   - `status`: `'failed'`
   - `error`: Exception message

### 4. Status Values

The `status` field can have the following values:

- **`processing`**: Job has started, email not yet sent
- **`sent`**: Successfully sent via provider
- **`failed`**: Send failed (template error, provider error, etc.)
- **`skipped`**: Email type disabled in notification settings
- **`deferred`**: Quota exceeded, email deferred to later

### 5. Handling Edge Cases

#### Email Type Disabled
```php
if (!EmailNotificationSetting::isEnabled($this->templateKey)) {
    $log->update(['status' => 'skipped', 'error' => 'Email type disabled...']);
    return;
}
```

#### Quota Exceeded
```php
if (!$quota->reserveQuota(1)) {
    DeferredEmail::create([...]);
    $log->update(['status' => 'deferred', 'error' => "Quota exceeded: {$reason}"]);
    return;
}
```

#### Validation Errors
```php
if (!empty($errors)) {
    $log->update(['status' => 'failed', 'error' => 'Variable validation failed...']);
    throw new \Exception(...);
}
```

#### Template Rendering Errors
```php
catch (\Exception $e) {
    EmailLog::where('correlation_id', $correlationId)->update([
        'status' => 'failed',
        'error' => 'Failed to render email template: ' . $e->getMessage(),
    ]);
}
```

## Benefits

### 1. Single Source of Truth
- One `EmailLog` row per email send
- No more duplicate logs for the same email
- Easy to track email history and retries

### 2. Complete Context
Every log entry has:
- Full template context (`template_key`, `user_id`)
- Correlation tracking (`correlation_id`)
- Provider details (`message_id`, `provider`)
- Attempt tracking (`attempt_count`)
- Performance metrics (`duration_ms`)
- Rendered content (for debugging/preview)

### 3. Retry-Safe
- Retries update the same row (increment `attempt_count`)
- No duplicate logs created on job retries
- Complete retry history in one row

### 4. Data Integrity
- Unique constraints prevent accidental duplicates
- Status progression is explicit and trackable
- Tags are sanitized and stored as arrays (Eloquent cast)

## Migration Guide

### For Developers

**Before (Old System)**:
```php
// Multiple log entries could be created
EmailLog::create([...]);  // Early minimal log
// ... later ...
EmailLog::create([...]);  // Full log with message_id
```

**After (New System)**:
```php
// Single log created and progressively updated
EmailLog::updateOrCreate(['correlation_id' => $id], [...]);
EmailLog::where('correlation_id', $id)->update([...]);
```

### For Queries

**Before**:
```php
// Multiple logs for same email
$logs = EmailLog::where('to', 'user@example.com')
    ->where('template_key', 'auth.password_reset')
    ->get();  // Could return 2+ rows for one send
```

**After**:
```php
// Single log per email
$log = EmailLog::where('correlation_id', $correlationId)->first();
// OR
$log = EmailLog::where('message_id', $messageId)->first();
```

## Testing

See `tests/Unit/Jobs/Email/SendEmailJobProgressiveLoggingTest.php` for comprehensive tests:

1. ✓ Creates initial log entry with processing status
2. ✓ Creates only one log entry per email send
3. ✓ Updates attempt_count on retries (no duplicates)
4. ✓ Updates to skipped status when email type disabled
5. ✓ Updates to failed status on validation errors

## Acceptance Criteria ✓

- [x] Sending a password reset email results in exactly one EmailLog row
- [x] Retries update the same row (attempt_count increments) rather than adding new rows
- [x] The log row has full context (user_id/template_key/correlation_id) and provider message_id once available
- [x] No more phantom entries (all logs have proper context)
- [x] Tags never trigger Resend validation (ASCII letters/numbers/_/- only)
- [x] ResendService does NOT create EmailLog rows (only returns EmailResult)
- [x] EmailManager is the canonical place for DB logging
- [x] SendEmailJob creates initial log and updates on failures

## Future Considerations

### Adding Retry History

If detailed retry history is needed in the future, consider adding a `retry_history` JSON field:

```php
'retry_history' => [
    ['attempt' => 1, 'error' => '...', 'timestamp' => '...'],
    ['attempt' => 2, 'error' => '...', 'timestamp' => '...'],
]
```

### Monitoring Queries

To monitor email status distribution:
```sql
SELECT status, COUNT(*) as count 
FROM email_logs 
WHERE created_at >= NOW() - INTERVAL 24 HOUR
GROUP BY status;
```

To find stuck "processing" emails:
```sql
SELECT * FROM email_logs 
WHERE status = 'processing' 
  AND created_at < NOW() - INTERVAL 1 HOUR;
```
