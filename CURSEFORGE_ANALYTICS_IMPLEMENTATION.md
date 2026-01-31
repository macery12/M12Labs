# CurseForge Analytics Implementation

## Overview

This implementation adds comprehensive tracking and analytics for CurseForge API requests, replacing the previous 429-error-only tracking with actual request-based metrics that display properly in the admin dashboard.

## Changes Made

### 1. Database Schema

**New Table: `curseforge_request_logs`**
- `id` - Primary key
- `requested_at` - Timestamp of the request (indexed)
- `endpoint` - The CurseForge API endpoint called
- `status_code` - HTTP status code of the response

**Migration File:** `database/migrations/2026_01_31_193000_create_curseforge_request_logs_table.php`

### 2. Model

**New Model: `CurseForgeRequestLog`**
- Location: `app/Models/CurseForgeRequestLog.php`
- Handles database interactions for request logs
- Timestamps disabled (uses custom `requested_at` field)
- Auto-cleanup of logs older than 25 hours

### 3. Service Updates

**File: `app/Services/Mods/CurseForgeService.php`**

#### New Method: `trackRequest()`
```php
private function trackRequest(string $endpoint, int $statusCode): void
```
- Records each API request to the database
- Stores endpoint path and status code
- Auto-cleans logs older than 25 hours
- Gracefully handles errors without breaking API calls

#### Updated Method: `getRateLimitUsage()`
```php
public function getRateLimitUsage(): array
```
**Returns:**
```php
[
    'requests_this_minute' => int,  // Count of requests in last 60 seconds
    'requests_this_hour' => int,    // Count of requests in last hour
    'limit_per_minute' => 20,       // Conservative estimate
    'limit_per_hour' => 1000,       // Conservative estimate
]
```

**Previous Implementation:** Returned 429 error counts and lockout status
**New Implementation:** Returns actual request counts with time-based windows

#### New Method: `get429ErrorTracking()`
```php
public function get429ErrorTracking(): array
```
- Maintains backward compatibility
- Returns legacy 429 error tracking data
- Deprecated but kept for potential future use

#### Updated Method: `makeRequest()`
- Now tracks all successful requests (200-299 status codes)
- Tracks failed requests (especially 429 rate limits)
- Maintains existing error handling and retry logic
- Auto-cleanup happens on each request

## Analytics Dashboard

**Location:** Admin Panel → Modules → Mods

**Displays:**
1. **Requests This Minute**
   - Current count vs. limit (20)
   - Warning if limit reached

2. **Requests This Hour**
   - Current count vs. limit (1000)
   - Visual progress indicator

**Previous Issue:** Displayed `null` for all metrics
**Fixed:** Now displays actual request counts

## Rate Limit Strategy

### Conservative Estimates
- **Per Minute:** 20 requests (conservative)
- **Per Hour:** 1000 requests (conservative)

These are conservative estimates as CurseForge doesn't publish exact rate limits. The actual limits may be higher.

### Request Throttling
- 1.5 second delay between requests (unchanged)
- Request serialization (one at a time)
- 429 error tracking with exponential backoff (unchanged)
- 24-hour lockout after 50 consecutive 429s (unchanged)

## Data Retention

- Logs are automatically cleaned up after 25 hours
- Ensures we always have data for:
  - Last full hour (for hourly metrics)
  - Last full minute (for minute metrics)
  - Historical context for debugging

## Migration Path

### For New Installations
1. Run migrations: `php artisan migrate`
2. Logs will start accumulating automatically
3. Analytics will display correctly from first request

### For Existing Installations
1. Run migrations: `php artisan migrate`
2. Initially shows 0 requests (expected)
3. As API calls are made, metrics populate
4. Full analytics available after first hour of usage

## Monitoring & Debugging

### Check Current Metrics
```php
$service = app(\Everest\Services\Mods\CurseForgeService::class);
$metrics = $service->getRateLimitUsage();
```

### View Recent Requests
```sql
SELECT * FROM curseforge_request_logs 
ORDER BY requested_at DESC 
LIMIT 100;
```

### Check for Rate Limiting Issues
```sql
SELECT 
    DATE_FORMAT(requested_at, '%Y-%m-%d %H:%i') as minute,
    COUNT(*) as requests,
    SUM(CASE WHEN status_code = 429 THEN 1 ELSE 0 END) as rate_limited
FROM curseforge_request_logs
WHERE requested_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY minute
ORDER BY minute DESC;
```

## Benefits

1. **Accurate Metrics:** Real request counts instead of null
2. **Better Visibility:** See actual API usage patterns
3. **Debugging:** Track which endpoints are called most
4. **Rate Limit Monitoring:** Proactively monitor before hitting limits
5. **Historical Data:** 25-hour retention for trend analysis

## Performance Considerations

- Database writes on every API call (minimal overhead)
- Indexed `requested_at` column for fast queries
- Auto-cleanup prevents table growth
- Queries use time-based indexes (efficient)

## Security

- No sensitive data stored in logs
- Endpoint paths don't include query parameters with secrets
- API keys never logged
- Read-only access for analytics display

## Testing

### Verify Tracking Works
1. Make a CurseForge API request (e.g., search mods)
2. Check database: `SELECT COUNT(*) FROM curseforge_request_logs;`
3. View admin analytics page
4. Verify metrics display properly

### Verify Auto-Cleanup
1. Manually insert old log: `INSERT INTO curseforge_request_logs (requested_at, endpoint, status_code) VALUES (DATE_SUB(NOW(), INTERVAL 26 HOURS), '/test', 200);`
2. Make a new API request
3. Check that old log is deleted

## Future Enhancements

Potential improvements:
1. **Charts:** Visual graphs of usage over time
2. **Alerts:** Email notifications when approaching limits
3. **Endpoint Breakdown:** Show which endpoints are used most
4. **Response Times:** Track API response latency
5. **User Attribution:** Track which users make most requests (privacy considerations)

## Compatibility

- **PHP:** 8.1+ (required by Jexactyl framework)
- **Laravel:** 10.x (as used by Jexactyl)
- **MySQL:** 5.7+ or MariaDB 10.3+
- **PostgreSQL:** 11+ (if using Postgres)

## Troubleshooting

### Metrics Show 0
- **Cause:** No API requests made yet
- **Solution:** Make a mod search or install

### Metrics Not Updating
- **Cause:** Migration not run
- **Solution:** Run `php artisan migrate`

### Old Logs Not Cleaning
- **Cause:** No recent API requests
- **Solution:** Normal - cleanup happens on next request

### Database Errors
- **Cause:** Migration not run or table missing
- **Solution:** Run `php artisan migrate` and check logs
