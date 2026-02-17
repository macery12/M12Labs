# Email Logging System Architecture

## Overview

The email logging system has been completely refactored to provide reliable, non-duplicated email lifecycle tracking with complete attempt history.

## Architecture

### Two-Table Model

#### 1. `email_deliveries` Table
**Purpose**: One canonical row per email lifecycle  
**What it tracks**: The overall delivery status of each email

**Key Fields**:
- `correlation_id` (UUID, unique) - Stable identifier for the email across retries
- `template_key` - Email template used (e.g., 'auth.password_reset')
- `recipient` - Email address
- `user_id` - Associated user (nullable)
- `subject` - Email subject line
- `provider` - Email provider (default: 'resend')
- `status` - Current status: `queued`, `deferred`, `sending`, `sent`, `failed`, `skipped`, `blocked`
- `attempts` - Number of send attempts
- `last_attempt_at` - Timestamp of most recent attempt
- `sent_at` - Timestamp when successfully sent (nullable)
- `last_message_id` - Provider's message ID from last attempt
- `last_status_code` - HTTP status code from last attempt
- `last_error` - Error message from last attempt (nullable)
- `tags` - JSON array of tags

#### 2. `email_delivery_attempts` Table
**Purpose**: Append-only history of each provider API call  
**What it tracks**: Every individual attempt to send the email

**Key Fields**:
- `delivery_id` - Foreign key to email_deliveries
- `attempt_number` - Sequential attempt number (1, 2, 3...)
- `started_at` - When attempt began
- `finished_at` - When attempt completed (nullable)
- `duration_ms` - How long the attempt took
- `success` - Boolean success flag
- `status` - Attempt status: `sending`, `sent`, `failed`
- `provider_message_id` - Message ID from provider
- `status_code` - HTTP status code
- `error` - Error message (nullable)
- `request_payload` - Request data (only when debug mode enabled)
- `response_payload` - Response data (only when debug mode enabled)
- `exception_class` - Exception class name (nullable)
- `stacktrace` - Full stack trace (only when debug mode enabled)

## Data Flow

### 1. Email Dispatch
```
EmailNotificationListener
  └─> Generate correlation_id (UUID)
  └─> Dispatch SendEmailJob
```

### 2. Job Processing
```
SendEmailJob
  └─> Check for existing delivery (by correlation_id)
  └─> Create delivery if first attempt
  └─> Check if email type is enabled
  └─> Check rate limits (quota)
      ├─> If exceeded: markDeferred()
      └─> If OK: continue
  └─> Call EmailManager
```

### 3. Email Manager
```
EmailManager::sendFromTemplate()
  └─> Start attempt via tracker
  └─> Render email template
  └─> Call ResendService
  └─> Finish attempt (success or failure)
  └─> Sync delivery status from attempt
```

### 4. Provider Call
```
ResendService::send()
  └─> Call ResendHttpClient
  └─> Return structured EmailResult
      ├─> message_id
      ├─> status_code
      ├─> success flag
      └─> error (if any)
```

## Key Components

### EmailDeliveryTracker
**The ONLY service that writes to the logging tables.**

**Methods**:
- `startDelivery()` - Create initial delivery record (status: 'queued')
- `markDeferred()` - Mark as deferred due to rate limiting
- `markSkipped()` - Mark as skipped (email type disabled)
- `startAttempt()` - Create attempt record before provider call
- `finishAttemptSuccess()` - Update attempt and delivery on success
- `finishAttemptFailure()` - Update attempt and delivery on failure
- `syncDeliveryFromAttempt()` - Sync delivery status from latest attempt
- `generateDebugBundle()` - Export delivery + attempts as JSON

### Correlation ID Rules
1. Generated ONCE at dispatch time (EmailNotificationListener)
2. NEVER regenerated in EmailManager or SendEmailJob
3. Passed through entire flow
4. Retries reuse same correlation_id
5. Ensures one delivery row per email, multiple attempt rows

### Debug Mode
When `APP_DEBUG=true` or `EMAIL_LOG_DEBUG=true`:
- Stores sanitized request/response payloads
- Stores exception stack traces
- Includes debug data in debug bundle endpoint

When debug mode is disabled:
- Only stores summary fields (status_code, error, duration)
- Saves storage space
- Recommended for production

**Sanitization**: All payloads automatically redact:
- api_key, apiKey
- token, authorization
- password, secret

## Status Lifecycle

```
queued → sending → sent
   ↓        ↓        
deferred  failed
   ↓
skipped
blocked
```

- **queued**: Initial state when delivery created
- **deferred**: Rate limited, scheduled for later
- **sending**: Currently attempting to send
- **sent**: Successfully delivered
- **failed**: All attempts failed
- **skipped**: Email type disabled or sending disabled
- **blocked**: Recipient blocked (future use)

## API Endpoints

### List Deliveries
```
GET /api/application/email/logs
```
Returns paginated list of email deliveries with filters.

### Get Delivery Details
```
GET /api/application/email/logs/{id}
```
Returns delivery + all attempts.

### Debug Bundle
```
GET /api/application/email/logs/{id}/debug-bundle
```
Returns JSON with complete delivery and attempt data.

**Response structure**:
```json
{
  "delivery": {
    "id": 123,
    "correlation_id": "uuid",
    "template_key": "auth.password_reset",
    "recipient": "user@example.com",
    "status": "sent",
    "attempts": 2,
    ...
  },
  "attempts": [
    {
      "id": 456,
      "attempt_number": 1,
      "status": "failed",
      "error": "...",
      ...
    },
    {
      "id": 457,
      "attempt_number": 2,
      "status": "sent",
      "provider_message_id": "msg-123",
      ...
    }
  ]
}
```

## Testing

### Unit Tests
```bash
vendor/bin/phpunit tests/Unit/Services/Email/EmailDeliveryTrackerTest.php
```

Tests cover:
- Creating deliveries
- Starting/finishing attempts
- Success/failure handling
- Deferred/skipped status
- Multiple retries
- Debug bundle generation

### Manual Testing
1. Send test email: Admin → Email Settings → Send Test
2. Check logs: Admin → Email Activity
3. Verify:
   - One delivery row created
   - Attempt count = 1
   - Status = 'sent'
4. Force failure and retry to verify:
   - Attempt count increments
   - Multiple attempt rows
   - No duplicate deliveries

## Migration

### From Old System
```bash
php artisan migrate:fresh
```

This will:
1. Drop old `email_logs` table
2. Create new `email_deliveries` table
3. Create new `email_delivery_attempts` table

**Note**: This is a testing environment. No data migration is performed.

### Archived Files
Old email logging code has been archived:
- `app/Models/EmailLog.php.archived`
- `database/migrations/archived_2026_02_15_045959_create_email_logs_table.php`
- `database/migrations/archived_2026_02_15_180000_add_email_notification_fields_to_email_logs.php`
- `database/migrations/archived_2026_02_16_000100_add_activity_log_fields_to_email_logs_table.php`

## Configuration

### Environment Variables
```env
# Enable detailed debug logging for email attempts
# WARNING: Stores request/response payloads - disable in production
EMAIL_LOG_DEBUG=false
```

### Config File
`config/mail.php`:
```php
'log_debug' => env('EMAIL_LOG_DEBUG', false),
```

## Troubleshooting

### No emails being logged
- Check that EmailDeliveryTracker service is being injected
- Verify correlation_id is being passed through
- Check logs for exceptions

### Duplicate deliveries
- Should not happen with new system
- If seen, check correlation_id generation
- Ensure EmailNotificationListener generates ID only once

### Missing attempt records
- Check that startAttempt() is called before provider call
- Verify finishAttempt*() is called in try/catch

### Debug data not storing
- Check APP_DEBUG or EMAIL_LOG_DEBUG settings
- Verify debug mode is enabled
- Check payload sanitization isn't removing all data

## Performance Considerations

- Indexes on correlation_id, user_id, template_key, status, created_at
- Unique constraint on (delivery_id, attempt_number)
- JSON columns for tags and payloads (debug mode only)
- Consider archiving old attempts after X days if storage is concern

## Security

- All payloads sanitized before storage
- Secrets/tokens/passwords automatically redacted
- Debug mode should be disabled in production
- Stack traces only stored in debug mode
