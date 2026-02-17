# Email Logging System - Deployment Guide

## Issue Diagnosis

If emails are sending successfully but not appearing in the Email Activity Log UI, the issue is likely that the database migrations have not been run.

## Root Cause

The email logging system was refactored from a single-table (`email_logs`) to a two-table architecture (`email_deliveries` + `email_delivery_attempts`). The production environment needs the new tables to be created.

## How to Check if Migrations Are Needed

Run this in production:

```bash
php artisan migrate:status | grep email
```

Look for these migrations:
- `2026_02_16_220000_create_email_deliveries_table` - Should show "Ran"
- `2026_02_16_220001_create_email_delivery_attempts_table` - Should show "Ran"  
- `2026_02_16_220002_drop_email_logs_table` - Should show "Ran"

If they show "Pending", the tables don't exist yet.

## Alternative Check - Direct Database Query

```sql
SHOW TABLES LIKE 'email_deliveries';
SHOW TABLES LIKE 'email_delivery_attempts';
SHOW TABLES LIKE 'email_logs';
```

**Expected result:**
- `email_deliveries` - EXISTS
- `email_delivery_attempts` - EXISTS
- `email_logs` - DOES NOT EXIST (should be dropped)

## Deployment Steps

### Step 1: Backup Database (Critical!)

```bash
# Backup the entire database
mysqldump -u [username] -p [database_name] > backup_before_email_migration_$(date +%Y%m%d_%H%M%S).sql

# Or backup just email tables
mysqldump -u [username] -p [database_name] email_logs deferred_emails email_quotas email_notification_settings > email_tables_backup_$(date +%Y%m%d_%H%M%S).sql
```

### Step 2: Run Migrations

```bash
php artisan migrate
```

This will:
1. Create `email_deliveries` table
2. Create `email_delivery_attempts` table
3. Drop `email_logs` table (old structure)

### Step 3: Verify Tables Were Created

```bash
php artisan tinker
```

Then in tinker:
```php
DB::select("SHOW TABLES LIKE 'email_deliveries'");
DB::select("SHOW TABLES LIKE 'email_delivery_attempts'");
\Everest\Models\EmailDelivery::count();
\Everest\Models\EmailDeliveryAttempt::count();
exit
```

### Step 4: Test Email Sending

Send a test email:

```bash
php artisan tinker
```

```php
$user = \Everest\Models\User::first();
event(new \Everest\Events\Email\PasswordResetRequested(
    $user,
    url('/reset-password/test')
));
exit
```

### Step 5: Verify Data in New Tables

```bash
php artisan tinker
```

```php
// Check if delivery was created
\Everest\Models\EmailDelivery::latest()->first();

// Check if attempt was created
\Everest\Models\EmailDeliveryAttempt::latest()->first();
exit
```

### Step 6: Check Frontend UI

1. Navigate to Admin → Email → Activity Log
2. You should now see email logs appearing
3. Click "View Details" to see attempt history

## Data Migration Notes

**IMPORTANT:** This migration does NOT preserve old `email_logs` data. The old table is dropped.

- If you need to preserve historical email logs, export them before running migrations
- After migration, only new emails will be logged in the new tables
- Historical data from `email_logs` will be lost when the table is dropped

To preserve old logs:
```sql
-- Export to CSV before migration
SELECT * FROM email_logs INTO OUTFILE '/tmp/email_logs_export.csv'
FIELDS TERMINATED BY ',' 
ENCLOSED BY '"'
LINES TERMINATED BY '\n';
```

## Rollback Plan (If Issues Occur)

If you encounter issues after migration:

1. **Restore database from backup:**
```bash
mysql -u [username] -p [database_name] < backup_before_email_migration_YYYYMMDD_HHMMSS.sql
```

2. **Roll back migrations:**
```bash
php artisan migrate:rollback --step=3
```

3. **Verify old tables are back:**
```sql
SHOW TABLES LIKE 'email_logs';
```

## Common Issues

### Issue 1: "Table 'email_deliveries' doesn't exist"

**Cause:** Migrations not run  
**Solution:** Run `php artisan migrate`

### Issue 2: Frontend shows no logs but emails send successfully

**Cause:** New tables don't exist, writes are failing silently  
**Solution:** Run `php artisan migrate` and restart queue workers

### Issue 3: Duplicate key errors after migration

**Cause:** Old data conflicts with new unique constraints  
**Solution:** This shouldn't happen since old table is dropped. If it does, clear failed jobs:
```bash
php artisan queue:flush
```

### Issue 4: Queue workers still using old code

**Cause:** Queue workers need to be restarted after deployment  
**Solution:**
```bash
php artisan queue:restart
# Or if using supervisor:
supervisorctl restart all
```

## Post-Deployment Verification Checklist

- [ ] Migrations ran successfully
- [ ] Tables `email_deliveries` and `email_delivery_attempts` exist
- [ ] Table `email_logs` has been dropped
- [ ] Test email sends and appears in database
- [ ] Email Activity Log UI shows the test email
- [ ] "View Details" modal displays attempt information
- [ ] Queue workers have been restarted
- [ ] No errors in Laravel logs

## Architecture Changes

The new system uses:

**email_deliveries table:**
- One row per email lifecycle
- Tracks overall status (queued → sending → sent/failed)
- Fields: correlation_id (unique), recipient, status, attempts, etc.

**email_delivery_attempts table:**
- One row per provider API call
- Tracks each retry attempt separately
- Fields: delivery_id, attempt_number, started_at, duration_ms, error, etc.

**Benefits:**
- No duplicate log entries
- Complete retry history
- Better debugging with attempt-level details
- Correlation ID prevents duplicates across retries

## Support

If you encounter issues not covered here:

1. Check Laravel logs: `storage/logs/laravel.log`
2. Check queue worker logs if using queues
3. Verify database connection and permissions
4. Ensure all queue workers are restarted after deployment
