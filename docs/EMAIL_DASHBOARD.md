# Email Dashboard UI + Email Activity Log Viewer

## Overview

This implementation provides a comprehensive admin interface for managing and debugging the email notification system. It includes three main sections accessible via tabs:

1. **Settings** - Configure email provider, notification templates, and send test emails
2. **Activity Log** - View and debug all email sending attempts with detailed filtering
3. **Deferred Queue** - Monitor and manage emails that have been deferred due to rate limits

## Features Implemented

### 1. Email Settings Dashboard

**Location**: `/admin/email` (Settings tab)

**Components**:
- `ResendSettings.tsx` - Email provider configuration (API key, from email, reply-to)
- `NotificationSettings.tsx` - Email notification template toggles organized by category:
  - Authentication & Security
  - Billing & Payments
  - Server Notifications
- `SendTestEmail.tsx` - Send test emails to verify configuration
- `SendCustomEmail.tsx` - Send custom HTML emails

**Features**:
- Global kill switch to disable all notifications
- Per-template enable/disable toggles
- Rate limit exempt badges
- Template key display for debugging

### 2. Email Activity Log Viewer

**Location**: `/admin/email/activity`

**Component**: `EmailActivityLog.tsx`

**Features**:
- **Filtering**:
  - Status (sent, failed, deferred, blocked, skipped)
  - Template key dropdown
  - Recipient search
  - Date range (custom or quick: 24h, 7d, 30d)
  - "Only failures" toggle
  - User ID filter
- **Sorting**: By created_at, status, template_key, recipient
- **Pagination**: Configurable items per page
- **Detail Modal** (`EmailLogDetailModal.tsx`):
  - Full log information
  - Sanitized template variables (tokens/secrets redacted)
  - Provider metadata
  - Retry history timeline
  - Related emails (by correlation ID)
  - Actions: Resend failed emails, Copy debug bundle

**Status Indicators**:
- Green badge: Sent successfully
- Red badge: Failed
- Yellow badge: Deferred or Blocked
- Gray badge: Skipped

### 3. Deferred Queue Monitor

**Location**: `/admin/email/queue`

**Component**: `DeferredQueueViewer.tsx`

**Features**:
- **Statistics**:
  - Total queued emails
  - Emails due now
  - Next scheduled send time
- **Queue Table**:
  - Recipient and user information
  - Template key
  - Deferral reason (daily_limit, monthly_limit)
  - Scheduled time with DUE indicator
  - Attempt count
- **Actions**:
  - Send Now - Force immediate sending
  - Cancel - Remove from queue
- **Auto-refresh**: Updates every 30 seconds

## Backend API Endpoints

All endpoints are prefixed with `/api/application/email/`

### Email Logs
- `GET /logs` - List logs with filters and pagination
  - Query params: status, template_key, recipient, user_id, only_failures, date_from, date_to, sort_by, sort_dir, per_page, page
- `GET /logs/{id}` - Get detailed log entry
- `POST /logs/{id}/resend` - Resend a failed email
- `GET /logs/stats` - Get aggregate statistics
  - Query params: days (default: 7)
- `GET /logs/templates` - Get list of all template keys

### Deferred Queue
- `GET /deferred` - List deferred emails with stats
  - Query params: status (due/pending), per_page, page
- `POST /deferred/{id}/send-now` - Send a deferred email immediately
- `DELETE /deferred/{id}` - Cancel a deferred email

## Database Schema

### email_logs Table

New/Enhanced Fields:
- `status` - Email status (sent, failed, deferred, blocked, skipped)
- `attempt_count` - Number of send attempts
- `duration_ms` - Send duration in milliseconds
- `metadata` - JSON field for provider responses, rate limit info, retry history
- `rendered_subject` - Actual rendered email subject
- `rendered_html` - Rendered HTML content for preview/resend
- `rendered_text` - Rendered plain text content
- `template_variables` - JSON field with template data (sanitized for display)

Existing Fields:
- `id`, `to`, `subject`, `template_key`, `correlation_id`, `message_id`, `provider`, `user_id`, `success`, `error`, `tags`, `created_at`, `updated_at`

### deferred_emails Table

Fields:
- `id`, `user_id`, `template_key`, `recipient`, `data`, `correlation_id`, `reason`, `scheduled_at`, `sent_at`, `attempts`, `created_at`, `updated_at`

## Security

1. **Access Control**: All endpoints require admin authentication (AdminSubject middleware)
2. **Variable Sanitization**: `EmailLog::getSanitizedVariables()` redacts sensitive keys:
   - resetUrl, token, api_key, password, secret
3. **Activity Logging**: All admin actions logged via Activity facade
4. **Input Validation**: Request validation for all inputs
5. **SQL Injection Protection**: Using Eloquent ORM with parameter binding

## Usage Examples

### Viewing Recent Failures
1. Navigate to `/admin/email/activity`
2. Check "Only show failures" checkbox
3. Select date range "7d"
4. View failed emails and click "View Details" for debugging

### Resending a Failed Email
1. Find the failed email in Activity Log
2. Click "View Details"
3. Review error message and template variables
4. Click "Resend" button
5. Email will be queued for resending

### Managing Deferred Emails
1. Navigate to `/admin/email/queue`
2. View statistics for queued emails
3. For urgent emails marked as "DUE", click "Send Now"
4. For unwanted emails, click "Cancel"

### Debugging Email Issues
1. Go to Activity Log
2. Search for recipient email
3. Open detail modal
4. Check:
   - Error message
   - Provider response in metadata
   - Template variables used
   - Retry history
5. Copy debug bundle for support ticket

## Migration Path

To apply the database changes:

```bash
php artisan migrate
```

This will run the migration:
- `2026_02_16_000100_add_activity_log_fields_to_email_logs_table.php`

## Frontend Build

After pulling changes:

```bash
npm install --legacy-peer-deps
npm run build
```

Or with pnpm:

```bash
pnpm install --no-frozen-lockfile
pnpm build
```

## Configuration

No additional configuration required. The system uses existing email settings from the Settings page.

## Future Enhancements

Optional improvements for consideration:
1. **Log Retention Job**: Cleanup command to delete old logs based on plan limits
2. **Template Preview**: Endpoint to preview email templates with sample data
3. **Export Logs**: CSV/JSON export functionality
4. **Advanced Stats**: Charts for email volume, success rates over time
5. **Webhook Integration**: Receive delivery status updates from email provider
6. **Bulk Actions**: Resend multiple failed emails at once

## Troubleshooting

### Logs not showing
- Check database migration has run
- Verify user has admin permissions
- Check browser console for API errors

### Resend not working
- Ensure original email had `rendered_html` stored
- Check email provider credentials in Settings
- Review error message in detail modal

### Deferred queue empty
- Queue only used when rate limits hit
- Check EmailQuota settings for user plan
- Verify SendEmailJob is dispatching correctly

## Support

For issues or questions, refer to:
- Backend code: `app/Http/Controllers/Api/Application/EmailActivityController.php`
- Frontend code: `resources/scripts/components/admin/modules/email/`
- Database models: `app/Models/EmailLog.php`, `app/Models/DeferredEmail.php`
