# PayPal Logging & Debugging Guide

## Overview

This guide explains the comprehensive logging system added to diagnose PayPal checkout issues. Use this when no logs are appearing or when trying to identify where the PayPal flow is failing.

## What Was Added

### Backend Logging (Laravel)

**File:** `app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php`

**Changes:**
1. Added proper Log facade import: `use Illuminate\Support\Facades\Log;`
2. Replaced all `\Log::` with `Log::` (proper facade usage)
3. Added immediate test log at start of `captureOrder()`

**The Immediate Test Log:**
```php
Log::info("=== PAYPAL CAPTURE ENDPOINT HIT ===", [
    'method' => $request->method(),
    'url' => $request->fullUrl(),
    'user_id' => $request->user()->id ?? 'not authenticated',
    'all_input' => $request->all(),
]);
```

**Why This Matters:**
- This log appears IMMEDIATELY when the endpoint is hit
- If this doesn't appear, the controller method is NOT being called
- Helps identify routing vs logic issues

### Frontend Logging (React/TypeScript)

**Files Modified:**
- `PayPalPaymentButton.tsx` - Button click and order creation
- `Processing.tsx` - Return from PayPal and capture

**Console Logs Added:**
- Form submission
- Validation results
- API calls (create, update, capture, status check)
- Success/failure outcomes
- Redirects

## How to Use This System

### Step 1: Prepare Your Environment

**Backend:**
```bash
# Clear all caches
php artisan optimize:clear

# Restart PHP-FPM
sudo systemctl restart php8.1-fpm

# Check log file exists and is writable
ls -la storage/logs/
```

**Frontend:**
```bash
# Rebuild frontend (if you pulled new code)
npm run build
# or
pnpm build
```

### Step 2: Watch Logs in Real-Time

**Terminal 1 - Backend Logs:**
```bash
# Watch today's Laravel log file
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log | grep -i paypal
```

**Terminal 2 - Browser Console:**
1. Open your browser (Chrome/Firefox)
2. Press F12 to open Developer Tools
3. Go to "Console" tab
4. Filter by "[PayPal" to see only PayPal logs

### Step 3: Test PayPal Checkout

1. Navigate to checkout page
2. Select a product and fill in details
3. Click "Pay with PayPal"
4. Watch BOTH log outputs simultaneously

## Understanding Log Output

### Successful Flow

**Frontend Console (Browser):**
```
[PayPal] Form submit initiated
[PayPal] Validating inputs: {product: 1, selectedNode: 1, serverName: "test"}
[PayPal] Return URL: https://example.com/account/billing/processing?processor=paypal
[PayPal] Step 1: Creating PayPal order...
[PayPal] Order created: {id: "123", approval_url: "https://paypal.com/..."}
[PayPal] Step 2: Updating order with details...
[PayPal] Order updated successfully
[PayPal] Redirecting to approval URL: https://paypal.com/...
```

**After Returning from PayPal:**
```
[PayPal Processing] Starting PayPal payment processing
[PayPal Processing] Order ID retrieved: 123
[PayPal Processing] Calling capturePayPalOrder...
[PayPal Processing] Capture successful, polling for status...
[PayPal Processing] Status check #1
[PayPal Processing] Status result: {processed: false, failed: false}
[PayPal Processing] Status check #2
[PayPal Processing] Status result: {processed: true, failed: false}
[PayPal Processing] Order processed successfully!
```

**Backend Logs (Laravel):**
```
[2026-01-30 12:34:56] === PAYPAL CAPTURE ENDPOINT HIT ===
[2026-01-30 12:34:56] PayPal capture requested
[2026-01-30 12:34:56] Found order for capture
[2026-01-30 12:34:56] PayPal order approval status: approved
[2026-01-30 12:34:56] Attempting to capture PayPal payment
[2026-01-30 12:34:57] PayPal capture result: COMPLETED
[2026-01-30 12:34:57] Starting order fulfillment
[2026-01-30 12:34:58] Order fulfillment completed successfully
[2026-01-30 12:34:58] Final order status after fulfillment: processed
```

### Diagnostic Scenarios

#### Scenario 1: No Logs Anywhere

**Symptoms:**
- No frontend console logs
- No backend Laravel logs

**Possible Causes:**
1. JavaScript error preventing execution
2. Button not actually clicked
3. Form validation blocking submit

**How to Diagnose:**
```javascript
// Check browser console for ANY errors (not just PayPal)
// Look for red error messages
```

**Solutions:**
- Check browser console for JavaScript errors
- Verify button is not disabled
- Check Network tab for failed requests
- Clear browser cache

#### Scenario 2: Frontend Logs Only

**Symptoms:**
- Frontend console shows logs
- No backend Laravel logs

**Possible Causes:**
1. Route cache not cleared
2. Route not registered
3. API endpoint returning 404
4. Server/PHP error

**How to Diagnose:**
```bash
# Check if route exists
php artisan route:list | grep paypal

# Check web server error logs
tail -f /var/log/nginx/error.log
# or
tail -f /var/log/apache2/error.log

# Check PHP-FPM logs
tail -f /var/log/php8.1-fpm.log
```

**Solutions:**
```bash
# Clear route cache
php artisan route:clear

# Restart everything
php artisan optimize:clear
sudo systemctl restart php8.1-fpm
sudo systemctl restart nginx
```

#### Scenario 3: Backend Logs But Process Fails

**Symptoms:**
- "=== PAYPAL CAPTURE ENDPOINT HIT ===" appears
- But subsequent logs show errors

**Check Logs For:**
- "Order not found" → paypal_order_id not saved
- "Order not approved" → User cancelled or didn't complete payment
- "Capture failed: VOIDED" → Order expired
- "Fulfillment failed" → Server creation issue

**Solutions Based on Error:**

**"Order not found":**
```sql
-- Check if order exists
SELECT id, paypal_order_id, status FROM orders 
WHERE user_id = YOUR_USER_ID 
ORDER BY created_at DESC LIMIT 5;

-- If paypal_order_id is NULL, the bug is in order creation
```

**"Order not approved":**
- User needs to complete payment on PayPal
- Check PayPal sandbox for order status

**"Capture failed":**
- Check exact status returned
- Verify PayPal credentials
- Check PayPal Developer Dashboard for order

**"Fulfillment failed":**
- Check the specific error message
- May be node unavailable, resource limits, etc.

#### Scenario 4: Everything Logs But Still Goes to Cancel

**Symptoms:**
- Capture succeeds (log shows "COMPLETED")
- Fulfillment succeeds (log shows "completed successfully")
- But frontend still shows cancelled

**Possible Causes:**
1. Status polling returning wrong data
2. Timing issue - order not marked processed quickly enough
3. Frontend timeout

**How to Diagnose:**
```javascript
// Frontend console should show:
[PayPal Processing] Status result: {processed: false, failed: false}
// Or
[PayPal Processing] Status result: {processed: true, failed: false}

// If it shows failed: true, but backend shows success, there's a mismatch
```

**Solutions:**
- Check `checkPayPalOrderStatus` endpoint logs
- Verify order status in database matches what API returns
- May need to increase polling timeout or interval

## Log Levels

The logging uses Laravel's log levels:

- `Log::info()` - Normal flow, success messages
- `Log::warning()` - Non-critical issues (order not approved yet, etc.)
- `Log::error()` - Critical failures (capture failed, fulfillment failed)

**To see ALL logs (not just errors):**
```bash
# Make sure LOG_LEVEL in .env is 'debug' or 'info'
LOG_LEVEL=debug

# Then restart
php artisan config:clear
```

## Checking Log Configuration

**Verify logging is configured correctly:**

```php
// config/logging.php should have:
'default' => env('LOG_CHANNEL', 'daily'),

'daily' => [
    'driver' => 'daily',
    'path' => storage_path('logs/laravel.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 7,
],
```

**Check .env file:**
```bash
LOG_CHANNEL=daily
LOG_LEVEL=debug
```

**Verify log directory permissions:**
```bash
# Should be writable by web server user
ls -la storage/logs/
# Fix if needed:
chown -R www-data:www-data storage/logs/
chmod -R 775 storage/logs/
```

## Testing the Logging System

**Simple test to verify logging works:**

```bash
# Run this artisan command
php artisan tinker

# Then type:
\Log::info("Test log entry from tinker");

# Exit tinker (Ctrl+D)

# Check if it appears in logs:
tail -1 storage/logs/laravel-$(date +%Y-%m-%d).log
```

If the test log doesn't appear, your logging system has a problem unrelated to PayPal.

## Next Steps

Once you have log output:

1. **If "=== PAYPAL CAPTURE ENDPOINT HIT ===" appears:**
   - Read subsequent logs to find exact failure point
   - Apply specific fix based on error message

2. **If NO logs appear:**
   - Check JavaScript console for errors
   - Verify routes are registered
   - Check server error logs
   - Verify logging system works (use test above)

3. **If logs show success but UI shows failure:**
   - Issue is in frontend polling logic
   - Check status check endpoint specifically
   - May be timing/race condition

## Common Solutions Summary

```bash
# The universal fix - clear everything and restart
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
sudo systemctl restart php8.1-fpm
sudo systemctl restart nginx

# Rebuild frontend
npm run build

# Fix permissions
chown -R www-data:www-data storage/
chmod -R 775 storage/
```

## Getting Help

If you've followed this guide and still can't identify the issue, provide:

1. **Frontend console output** (full log from start to error)
2. **Backend log output** (grep for PayPal in logs)
3. **Network tab export** (HAR file showing API calls)
4. **Error messages** (exact text of any errors)
5. **Database query** (SELECT * FROM orders WHERE user_id=X ORDER BY id DESC LIMIT 1)

This will help diagnose the issue quickly.
