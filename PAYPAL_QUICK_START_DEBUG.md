# PayPal Data Not Saving - Quick Start Debug Guide

## TL;DR - Do This Right Now

```bash
# 1. Update code
git pull origin copilot/add-standalone-paypal-module

# 2. Run migration (adds the 7 missing columns!)
php artisan migrate

# 3. Clear caches
php artisan optimize:clear
sudo systemctl restart php8.1-fpm

# 4. Rebuild frontend (adds debugging logs!)
npm run build  # or: pnpm build

# 5. Open TWO windows side-by-side:

# Window 1: Browser
# - Go to your site
# - Press F12
# - Click "Console" tab
# - Enable "Preserve log"

# Window 2: Terminal
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log

# 6. Attempt PayPal checkout and WATCH BOTH WINDOWS

# 7. Based on what you see, jump to the right section below
```

## What You'll See (Quick Reference)

### ✅ Success - Everything Works!

**Browser Console:**
```
[PayPal] Capture API call - Order ID: 5AB...
[PayPal] Capture successful: {success: true}
```

**Terminal:**
```
=== PAYPAL CAPTURE ROUTE HIT ===
=== PAYPAL CAPTURE ENDPOINT HIT ===
Saved PayPal transaction details
Order fulfillment completed successfully
```

**Database:**
```sql
SELECT paypal_capture_id, paypal_payer_email 
FROM orders 
WHERE payment_processor = 'paypal' 
ORDER BY id DESC LIMIT 1;

-- Should show data, not NULL!
```

→ **You're done! It's working!**

---

### ❌ Scenario 1: No Browser Console Logs

**You see:**
- Browser console is empty
- No "[PayPal]" messages at all

**What's wrong:**
- JavaScript error preventing execution
- OR button not actually being clicked
- OR PayPal not enabled

**Quick fix:**
1. Check browser console for RED error messages
2. Ensure PayPal is enabled in Admin → Billing → Integrations
3. Try different browser (Firefox, Chrome, Edge)
4. Check if you filled all required fields

---

### ❌ Scenario 2: Browser Logs But No Terminal Logs

**You see:**
```
Browser: [PayPal] Capture API call - Order ID: 5AB...
Terminal: (nothing)
```

**What's wrong:**
- Capture API request failing (404, 500, network error)

**Quick fix:**
```bash
# Clear route cache again
php artisan route:clear
php artisan route:cache
sudo systemctl restart php8.1-fpm

# Verify route exists
php artisan route:list | grep "paypal/capture"
# Should show: POST api/client/billing/paypal/capture

# Check browser console for error after "[PayPal] Capture API call"
# Look for: [PayPal] Capture failed: {status: 404, ...}
```

**If still 404:**
- Route not registered (code issue)
- Cache still not cleared (try reboot)

**If 500 error:**
```bash
# Check Laravel error log (without grep)
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log
# Try checkout again and watch for errors
```

---

### ❌ Scenario 3: Terminal Shows Route But Not Controller

**You see:**
```
Terminal: === PAYPAL CAPTURE ROUTE HIT ===
Terminal: (nothing else, no "ENDPOINT HIT")
```

**What's wrong:**
- Middleware blocking request
- Authentication failure
- Exception before controller

**Quick fix:**
1. Look at terminal for error after "ROUTE HIT"
2. Common errors:
   - "Unauthenticated" → Log out and log back in
   - "CSRF token mismatch" → Clear cache: `php artisan cache:clear`
   - "Too Many Requests" → Wait 1 minute, try again

---

### ❌ Scenario 4: Controller Logs But Data Not Saved

**You see:**
```
Terminal: === PAYPAL CAPTURE ENDPOINT HIT ===
Terminal: Saved PayPal transaction details
Database: Still NULL!
```

**What's wrong:**
- Migration not run (columns don't exist)
- Database error

**Quick fix:**
```bash
# 1. Check if migrations ran
php artisan migrate:status | grep paypal

# Should show:
# Ran  2026_01_30_183000_add_paypal_order_id_to_orders_table
# Ran  2026_01_31_020000_add_paypal_transaction_details_to_orders_table

# 2. If NOT showing "Ran", run migrations:
php artisan migrate

# 3. Verify columns exist
mysql -u your_user -p your_database -e "SHOW COLUMNS FROM orders LIKE 'paypal%';"

# Should show 8 columns:
# paypal_order_id
# paypal_capture_id
# paypal_payer_id
# paypal_payer_email
# paypal_status
# paypal_amount
# paypal_currency
# paypal_captured_at

# 4. If columns missing, migration didn't run. Check for errors:
php artisan migrate --force
```

---

## Database Quick Check

```sql
-- Are the columns there?
SHOW COLUMNS FROM orders LIKE 'paypal%';

-- Check the latest PayPal order
SELECT 
    id,
    paypal_order_id,
    paypal_capture_id,
    paypal_payer_email,
    paypal_amount,
    paypal_currency,
    status,
    created_at
FROM orders 
WHERE payment_processor = 'paypal' 
ORDER BY id DESC 
LIMIT 1;
```

**Expected:**
- All 8 paypal_* columns exist
- Latest order has data in all fields (not NULL)
- status = 'processed'

**If NULL:**
- Columns exist but data not saved → Capture not executing
- Columns don't exist → Migration not run

---

## Browser Network Tab Check

1. Press F12 → Network tab
2. Try PayPal checkout
3. Look for request to "paypal/capture"
4. Click on it
5. Check status code:
   - **200** = Success (check database!)
   - **404** = Route not found (clear cache)
   - **500** = Server error (check Laravel log)
   - **403** = Auth issue (re-login)
   - **422** = Validation error (check request data)

---

## Common Mistakes

### 1. Didn't Rebuild Frontend
```bash
# MUST rebuild after pulling new code!
npm run build  # or: pnpm build
```

Without rebuild, new console logs won't exist!

### 2. Didn't Run Migration
```bash
# MUST run migration for new columns!
php artisan migrate
```

Without migration, columns don't exist!

### 3. Checking Old Orders
```
# Old orders from BEFORE the fix won't have data
# Only NEW orders after deploying will have data
```

Make a NEW test order!

### 4. Looking at Wrong Log
```bash
# Don't use:
tail -f storage/logs/laravel.log

# DO use:
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log
```

The date-specific file is where logs go!

---

## Still Stuck?

If none of the above scenarios match, you need the full debugging guide:

1. Read `PAYPAL_DATA_NOT_SAVING_DEBUG.md` (comprehensive)
2. Follow every step systematically
3. Provide the info it asks for if still stuck

---

## What Each Field Does (Quick Reference)

| Field | What It's For | Why Critical |
|-------|---------------|--------------|
| `paypal_order_id` | PayPal's order ID | Tracking |
| `paypal_capture_id` | Transaction ID | **Refunds!** |
| `paypal_payer_id` | Customer's PayPal account | Support |
| `paypal_payer_email` | Payer's email | Support |
| `paypal_status` | Payment status | Reconciliation |
| `paypal_amount` | Amount charged | Verification |
| `paypal_currency` | Currency code | Multi-currency |
| `paypal_captured_at` | When captured | Audit trail |

**Without `paypal_capture_id`, you CANNOT issue refunds!**

---

## Success Checklist

- [ ] Pulled latest code
- [ ] Ran `php artisan migrate`
- [ ] Cleared caches
- [ ] Restarted PHP-FPM
- [ ] Rebuilt frontend (`npm run build`)
- [ ] Opened browser console (F12)
- [ ] Opened terminal with logs
- [ ] Attempted PayPal checkout
- [ ] Saw logs in browser AND terminal
- [ ] Checked database - data is there!

If all checked, you're done! 🎉

If any unchecked, do that step and try again.

---

## Next Steps After It Works

1. Configure webhook in PayPal Dashboard (see admin panel for URL)
2. Test complete flow: order → pay → fulfill
3. Verify server gets created
4. Check order in billing history
5. Celebrate! 🎉

The PayPal integration is complete and will work perfectly once the capture endpoint is being reached!
