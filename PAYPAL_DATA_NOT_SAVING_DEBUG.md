# PayPal Transaction Data Not Saving - Complete Debugging Guide

## The Problem

**User Reports:**
- Only `paypal_order_id` is being saved to the database
- All other PayPal fields (capture_id, payer_email, amount, etc.) are NULL
- Even when orders fail/cancel, no data is saved
- No logs are appearing in Laravel logs

## Why This Is Critical

Without the full transaction data:
- ❌ Cannot issue refunds (need `paypal_capture_id`)
- ❌ Cannot support customers (need payer info)
- ❌ Cannot reconcile finances (need amounts)
- ❌ Cannot handle disputes (need transaction details)

## Root Cause Analysis

### What SHOULD Happen

```
1. User submits order
   → POST /products/{id}/paypal/order
   → Creates order, saves paypal_order_id ✓

2. User redirects to PayPal
   → Approves payment ✓

3. PayPal redirects back
   → /processing?token=xxx&processor=paypal ✓

4. Frontend captures payment
   → POST /paypal/capture
   → Saves 7 transaction fields ✗ NOT HAPPENING!

5. Order fulfilled
   → Server created ✗
```

### What IS Happening

```
1. User submits order
   → paypal_order_id saved ✓

2. User approves on PayPal ✓

3. Returns to processing page ✓

4. Capture endpoint NOT REACHED
   → No logs
   → No data saved
   → Order goes to cancelled ✗
```

## Triple-Layer Debugging System

We've added logging at THREE levels to identify exactly where it fails:

### Level 1: Frontend (Browser Console)
**Location:** Browser Developer Tools (F12) → Console tab

**Shows:**
- Is JavaScript calling the API?
- What order ID is being sent?
- What response or error is received?

**Example Logs:**
```
[PayPal] Capture API call - Order ID: 5AB12345CD678901E
[PayPal] Capture successful: {success: true, ...}
```

OR

```
[PayPal] Capture failed: {status: 404, message: "Not Found"}
```

### Level 2: Route Middleware (Laravel Log)
**Location:** `storage/logs/laravel-YYYY-MM-DD.log`

**Shows:**
- Did Laravel receive the request?
- Before controller executes
- Authentication state
- Request data

**Example Log:**
```
[2026-01-31 12:34:56] === PAYPAL CAPTURE ROUTE HIT ===
{
    "url": "https://example.com/api/client/billing/paypal/capture",
    "method": "POST",
    "user_id": 123,
    "input": {"order_id": "5AB12345CD678901E"}
}
```

### Level 3: Controller (Laravel Log)
**Location:** Same Laravel log file

**Shows:**
- Controller method execution
- Business logic flow
- Data being saved
- Errors encountered

**Example Logs:**
```
[2026-01-31 12:34:56] === PAYPAL CAPTURE ENDPOINT HIT ===
[2026-01-31 12:34:56] Found order for capture
[2026-01-31 12:34:57] PayPal capture result: COMPLETED
[2026-01-31 12:34:57] Saved PayPal transaction details
[2026-01-31 12:34:58] Order fulfillment completed successfully
```

## Step-by-Step Debugging Process

### Step 1: Prepare Environment

```bash
# Pull latest code
git pull origin copilot/add-standalone-paypal-module

# Clear all caches
php artisan optimize:clear

# Restart services
sudo systemctl restart php8.1-fpm

# Rebuild frontend (CRITICAL - includes new console logs!)
npm run build  # or pnpm build
```

### Step 2: Check Prerequisites

**Verify migrations ran:**
```bash
php artisan migrate:status | grep paypal
```

Should show:
```
Ran  2026_01_30_183000_add_paypal_order_id_to_orders_table
Ran  2026_01_31_020000_add_paypal_transaction_details_to_orders_table
```

**Verify columns exist:**
```bash
mysql -u your_user -p your_database -e "DESCRIBE orders;" | grep paypal
```

Should show:
```
paypal_order_id
paypal_capture_id
paypal_payer_id
paypal_payer_email
paypal_status
paypal_amount
paypal_currency
paypal_captured_at
```

**Verify route exists:**
```bash
php artisan route:list | grep "paypal/capture"
```

Should show:
```
POST  api/client/billing/paypal/capture  PayPalCheckoutController@captureOrder
```

### Step 3: Set Up Logging

**Terminal 1: Laravel Logs**
```bash
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log | grep -i paypal
```

**Terminal 2: All Logs (if PayPal not showing)**
```bash
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log
```

**Browser: Open DevTools**
- Press F12
- Click Console tab
- Enable "Preserve log" (so logs don't clear on navigation)

### Step 4: Attempt PayPal Checkout

1. Go to billing page
2. Select PayPal payment method
3. Fill in required fields
4. Click "Pay with PayPal"
5. **Watch both browser console AND terminal logs**
6. Complete payment on PayPal
7. **Watch logs when returned to site**

### Step 5: Analyze Results

## Debugging Scenarios

### Scenario A: No Frontend Console Logs

**Symptoms:**
- Browser console is empty
- No "[PayPal]" messages

**Possible Causes:**
1. JavaScript error preventing execution
2. Button not actually clicked
3. Form validation preventing submission
4. PayPal not selected as payment method

**What to Check:**
```
1. Browser console for JavaScript errors (red messages)
2. Is there any error message on the page?
3. Did you actually click the PayPal button?
4. Is PayPal selected as the payment method?
```

**Solution:**
- Check for JavaScript errors in console
- Ensure PayPal is enabled in admin settings
- Verify all required fields are filled
- Try different browser

### Scenario B: Frontend Logs But No Route Logs

**Symptoms:**
- Browser console shows: `[PayPal] Capture API call - Order ID: ...`
- Laravel log is empty (no route log)

**Possible Causes:**
1. Network error (CORS, blocked request)
2. Wrong URL (frontend calling wrong endpoint)
3. Route not registered (cache issue)
4. 404 error (route not found)

**What to Check:**

**In Browser Console:**
```
Look for error after the "Capture API call" log:
[PayPal] Capture failed: {status: 404, ...}
```

**In Browser Network Tab (F12 → Network):**
```
1. Find the request to "/paypal/capture"
2. Check status code:
   - 404 = Route not found
   - 500 = Server error
   - 403 = Authentication/permission
   - 0 = Network/CORS error
```

**Solutions:**

**If 404:**
```bash
# Clear route cache
php artisan route:clear
php artisan route:cache
sudo systemctl restart php8.1-fpm

# Verify route exists
php artisan route:list | grep paypal/capture
```

**If 500:**
```bash
# Check Laravel error logs (without grep)
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log
```

**If 403:**
```
# User not authenticated
# Check if logged in
# Check session hasn't expired
```

**If CORS/Network Error:**
```
# Check web server logs
sudo tail -f /var/log/nginx/error.log
# OR
sudo tail -f /var/log/apache2/error.log
```

### Scenario C: Route Logs But No Controller Logs

**Symptoms:**
- Laravel log shows: `=== PAYPAL CAPTURE ROUTE HIT ===`
- But NO log showing: `=== PAYPAL CAPTURE ENDPOINT HIT ===`

**Possible Causes:**
1. Middleware blocking before controller
2. Authentication failure
3. Exception thrown before controller method
4. Rate limiting

**What to Check:**

**Look at Laravel log for errors after route log:**
```
[2026-01-31 12:34:56] === PAYPAL CAPTURE ROUTE HIT ===
[2026-01-31 12:34:56] ERROR: Unauthenticated  <-- Problem!
```

**Common errors:**
- "Unauthenticated" → User not logged in
- "CSRF token mismatch" → Session issue
- "Too Many Requests" → Rate limit hit

**Solutions:**

**If authentication issue:**
```
# Clear sessions
php artisan cache:clear
php artisan config:clear

# Ensure user is logged in before checkout
```

**If rate limit:**
```
# Wait a minute and try again
# OR check throttle config in api-client.php
```

### Scenario D: Controller Logs But No Data Saved

**Symptoms:**
- Laravel log shows all capture logs including "Saved PayPal transaction details"
- But database still has NULL values

**Possible Causes:**
1. Migration not run (columns don't exist)
2. Database permissions issue
3. Column name mismatch
4. Transaction rollback

**What to Check:**

**Verify columns exist:**
```sql
DESCRIBE orders;
```

Look for these columns:
- paypal_capture_id
- paypal_payer_email
- paypal_amount
- etc.

**Check actual order:**
```sql
SELECT 
    id,
    paypal_order_id,
    paypal_capture_id,
    paypal_payer_email,
    paypal_amount,
    paypal_currency,
    paypal_status
FROM orders 
WHERE payment_processor = 'paypal' 
ORDER BY id DESC 
LIMIT 1;
```

**Solutions:**

**If columns don't exist:**
```bash
# Run migrations
php artisan migrate

# Check status
php artisan migrate:status
```

**If permission error in logs:**
```bash
# Fix permissions
sudo chown -R www-data:www-data storage/
sudo chmod -R 775 storage/
```

**If data still NULL but no errors:**
```
# Check Laravel log for the "Saved PayPal transaction details" message
# Look at what values it tried to save
# May be PayPal API returning unexpected structure
```

### Scenario E: Everything Works!

**Symptoms:**
- Frontend logs show successful capture
- Route logs show request received
- Controller logs show data saved
- But user thinks it's not working

**What to Check:**

**Verify data IS being saved:**
```sql
SELECT 
    id,
    paypal_order_id,
    paypal_capture_id,
    paypal_payer_email,
    paypal_amount,
    paypal_currency,
    paypal_status,
    status,
    created_at
FROM orders 
WHERE payment_processor = 'paypal' 
ORDER BY id DESC 
LIMIT 5;
```

**Look at status column:**
- If status = "processed" → Order fulfilled successfully!
- If status = "pending" → Still processing (check why)
- If status = "failed" → Failed during fulfillment (check logs)

**Check if looking at old orders:**
```
User might be checking old orders from before fix.
Only NEW orders after deploying will have data.
```

## Common Solutions Summary

### 1. Route Cache Not Cleared
```bash
php artisan optimize:clear
php artisan route:cache
sudo systemctl restart php8.1-fpm
```

### 2. Frontend Not Rebuilt
```bash
npm run build
# OR
pnpm build
```

### 3. Migrations Not Run
```bash
php artisan migrate
```

### 4. Columns Don't Exist
```bash
# Check if migration file exists
ls -la database/migrations/*paypal*

# Run migrations
php artisan migrate

# Verify
php artisan migrate:status
```

### 5. Permission Issues
```bash
sudo chown -R www-data:www-data storage/
sudo chmod -R 775 storage/
```

### 6. Session/Auth Issues
```bash
php artisan cache:clear
php artisan config:clear
php artisan session:clear
```

## Network Tab Analysis

### How to Use Browser Network Tab

1. Open DevTools (F12)
2. Click "Network" tab
3. Enable "Preserve log"
4. Attempt PayPal checkout
5. Look for request to "paypal/capture"

### What to Look For

**Request Details:**
```
URL: /api/client/billing/paypal/capture
Method: POST
Status: 200 (success) or error code
```

**Request Payload:**
```json
{
    "order_id": "5AB12345CD678901E"
}
```

**Response (if successful):**
```json
{
    "success": true,
    "message": "Order processed successfully",
    "order_id": 123
}
```

**Response (if error):**
```json
{
    "message": "Error description",
    "errors": {...}
}
```

### Status Codes Meaning

- **200** = Success
- **404** = Route not found (cache issue)
- **500** = Server error (check Laravel logs)
- **403** = Forbidden (auth issue)
- **422** = Validation error (check request data)
- **0** = Network error (CORS, blocked)

## Database Verification Queries

### Check Migration Status
```sql
SELECT migration, batch 
FROM migrations 
WHERE migration LIKE '%paypal%';
```

### Check Table Structure
```sql
SHOW COLUMNS FROM orders LIKE 'paypal%';
```

### Check Recent Orders
```sql
SELECT 
    id,
    user_id,
    payment_processor,
    paypal_order_id,
    paypal_capture_id,
    paypal_payer_email,
    paypal_amount,
    paypal_currency,
    paypal_status,
    status,
    created_at
FROM orders 
WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY id DESC;
```

### Find Orders Missing Data
```sql
SELECT 
    id,
    paypal_order_id,
    paypal_capture_id,
    status,
    created_at
FROM orders 
WHERE payment_processor = 'paypal'
AND paypal_order_id IS NOT NULL
AND paypal_capture_id IS NULL
ORDER BY id DESC 
LIMIT 10;
```

## What Success Looks Like

### Browser Console
```
[PayPal] Processing detected with PayPal: {token: 'abc123', processor: 'paypal'}
[PayPal Processing] Order ID retrieved: 5AB12345CD678901E
[PayPal Processing] Calling capturePayPalOrder...
[PayPal] Capture API call - Order ID: 5AB12345CD678901E
[PayPal] Capture successful: {success: true, message: "Order processed successfully", order_id: 123}
[PayPal Processing] Capture successful, polling for status...
[PayPal Processing] Status check #1
[PayPal Processing] Status result: {processed: true, ...}
[PayPal Processing] Order processed successfully!
```

### Laravel Log
```
[2026-01-31 12:34:56] === PAYPAL CAPTURE ROUTE HIT ===
[2026-01-31 12:34:56] === PAYPAL CAPTURE ENDPOINT HIT ===
[2026-01-31 12:34:56] Found order for capture: order_id=123
[2026-01-31 12:34:56] PayPal order approval status: true
[2026-01-31 12:34:56] Attempting to capture PayPal payment
[2026-01-31 12:34:57] PayPal capture result: COMPLETED
[2026-01-31 12:34:57] Saved PayPal transaction details: capture_id=9XY98765...
[2026-01-31 12:34:57] Starting order fulfillment
[2026-01-31 12:34:58] Order fulfillment completed successfully
```

### Database
```sql
mysql> SELECT id, paypal_capture_id, paypal_payer_email, paypal_amount, status 
       FROM orders WHERE id = 123;

+-----+--------------------+----------------------+---------------+-----------+
| id  | paypal_capture_id  | paypal_payer_email   | paypal_amount | status    |
+-----+--------------------+----------------------+---------------+-----------+
| 123 | 9XY98765ZW432109X  | customer@example.com |         29.99 | processed |
+-----+--------------------+----------------------+---------------+-----------+
```

## Still Having Issues?

If you've followed all steps and still have problems, provide:

1. **Frontend Console Output:**
   - Copy ALL console logs (Ctrl+A, Ctrl+C in console)
   - Include any errors (red messages)

2. **Laravel Log Output:**
   - Last 100 lines around the time of test
   - `tail -n 100 storage/logs/laravel-$(date +%Y-%m-%d).log`

3. **Network Tab:**
   - Screenshot of the "/paypal/capture" request
   - Or export as HAR file

4. **Database Query Results:**
   ```sql
   -- Run these and share results
   SELECT migration FROM migrations WHERE migration LIKE '%paypal%';
   SHOW COLUMNS FROM orders LIKE 'paypal%';
   SELECT id, paypal_* FROM orders WHERE payment_processor = 'paypal' ORDER BY id DESC LIMIT 1;
   ```

5. **Route Verification:**
   ```bash
   php artisan route:list | grep paypal
   ```

6. **Migration Status:**
   ```bash
   php artisan migrate:status | grep paypal
   ```

With this information, we can pinpoint the exact issue!

## Related Documentation

- `PAYPAL_NO_LOGS_SOLUTION.md` - General logging troubleshooting
- `PAYPAL_LOGGING_GUIDE.md` - How to use the logging system
- `PAYPAL_DATABASE_SUMMARY.md` - What data should be saved
- `PAYPAL_TRANSACTION_DATA.md` - Complete field reference
- `PAYPAL_CANCELLATION_DEBUGGING.md` - Order cancellation issues
- `PAYPAL_SETUP_GUIDE.md` - Complete setup from scratch

## Summary

The triple-layer logging system will reveal:
1. **WHERE** the request fails (frontend, route, or controller)
2. **WHY** it fails (error messages, status codes)
3. **WHAT** to do about it (specific solutions)

Follow the debugging process systematically, and you'll identify the root cause!
