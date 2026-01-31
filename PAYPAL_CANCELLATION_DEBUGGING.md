# PayPal Order Cancellation - Debugging Guide

## Issue
Orders are being marked as "cancelled" after completing PayPal checkout, even though payment may have been successful.

## Complete Flow Analysis

### Expected Flow
```
1. User clicks "Pay with PayPal"
2. POST /products/{id}/paypal/order → Creates order, gets approval URL
3. User redirects to PayPal → Completes payment
4. PayPal redirects back: /processing?token=xxx&processor=paypal
5. Frontend gets order ID from token
6. Frontend calls capturePayPalOrder(order_id)
7. Backend captures payment from PayPal
8. Backend fulfills order (creates server)
9. Order status → PROCESSED
10. Frontend polls checkPayPalOrderStatus
11. Gets status.processed = true
12. Redirects to success page ✅
```

### What Might Go Wrong

#### Issue 1: Capture Fails
```
capturePayPalOrder throws error
→ Frontend catches error
→ Navigates to /cancel ❌
```

**Possible Causes:**
- PayPal order not approved (user didn't complete payment)
- PayPal API error
- Network timeout
- Invalid credentials

#### Issue 2: Fulfillment Fails
```
Capture succeeds
→ fulfillOrder() throws exception
→ Order stays PENDING
→ Status check returns pending
→ Eventually times out or goes to cancel
```

**Possible Causes:**
- Node not available
- Resource limits exceeded
- Database error
- Server creation fails

#### Issue 3: Status Never Updates
```
Capture + fulfillment succeed
→ But order status not updated to PROCESSED
→ Polling keeps returning pending
→ Times out after 2 minutes
→ Shows warning but doesn't navigate
```

**Possible Causes:**
- Database transaction not committed
- Race condition
- Order model not refreshed

## Debugging Steps

### Step 1: Enable Debug Mode
Edit `.env`:
```env
APP_DEBUG=true
APP_ENV=local
```

### Step 2: Clear All Caches
```bash
php artisan optimize:clear
sudo systemctl restart php8.1-fpm
```

### Step 3: Watch Logs in Real-Time
```bash
tail -f storage/logs/laravel.log | grep -i paypal
```

### Step 4: Attempt Checkout
Go through complete PayPal checkout flow.

### Step 5: Analyze Log Output

Look for these key log entries in order:

#### A. Capture Request
```
PayPal capture requested
```
- ✅ If present: Capture endpoint called
- ❌ If missing: Frontend not calling capture (check browser console)

#### B. Order Found
```
Found order for capture
```
- ✅ If present: Order exists in database
- ❌ If missing: Order not created or paypal_order_id not saved

#### C. Approval Status
```
PayPal order approval status {"is_approved": true}
```
- ✅ If true: User completed PayPal checkout
- ❌ If false: User didn't complete payment or PayPal API issue

#### D. Capture Result
```
PayPal capture result {"capture_status": "COMPLETED"}
```
- ✅ If COMPLETED: Payment captured successfully
- ❌ If anything else: Payment failed

#### E. Fulfillment Start
```
Starting order fulfillment
```
- ✅ If present: Attempting to create server
- ❌ If missing: Capture failed before fulfillment

#### F. Fulfillment Success
```
Order fulfillment completed successfully
```
- ✅ If present: Server should be created
- ❌ If missing with error: Check error message

#### G. Final Status
```
Final order status after fulfillment {"status": "processed"}
```
- ✅ If processed: Everything worked!
- ❌ If pending: Fulfillment didn't update status

### Step 6: Check Database

```sql
-- Find the order
SELECT id, status, payment_processor, paypal_order_id, user_id, product_id, server_id
FROM orders 
WHERE payment_processor = 'paypal' 
ORDER BY created_at DESC 
LIMIT 5;

-- Check if server was created
SELECT id, name, owner_id, node_id, allocation_id
FROM servers
WHERE owner_id = YOUR_USER_ID
ORDER BY created_at DESC
LIMIT 5;
```

## Common Issues & Solutions

### Issue: "Order not found" Error

**Log Entry:**
```
PayPal capture failed: Order not found
```

**Cause:** `paypal_order_id` not saved to database

**Solution:** 
- Check `CreateOrderService` has `$order->paypal_order_id = ...` line
- Verify migration added `paypal_order_id` column
- Run migration if needed: `php artisan migrate`

**Check:**
```sql
DESCRIBE orders; -- Should show paypal_order_id column
```

### Issue: "PayPal order is not approved yet"

**Log Entry:**
```
PayPal order not approved yet
```

**Cause:** User didn't complete payment on PayPal, or closed window

**Solution:**
- This is expected if user cancelled
- User should retry payment

### Issue: "Failed to capture PayPal payment: VOIDED"

**Log Entry:**
```
PayPal capture failed {"actual_status": "VOIDED"}
```

**Cause:** Order expired or was cancelled

**Solution:**
- PayPal orders expire after 3 hours
- User should create new order

### Issue: Capture succeeds but "Order fulfillment failed"

**Log Entry:**
```
Order fulfillment failed {"error": "..."}
```

**Cause:** Server creation issue (node full, resource limits, etc.)

**Solution:**
- Check error message for specific issue
- Verify node has capacity
- Check product limits
- Check egg exists

### Issue: No logs at all

**Cause:** Frontend not calling capture endpoint

**Solution:**
- Check browser console for JavaScript errors
- Verify `processor=paypal` parameter in URL
- Check if redirect from PayPal happened
- Clear browser cache and try again

## Browser Console Debugging

Open browser DevTools (F12) → Console tab

### Look for:
```javascript
// Success
GET /api/client/billing/paypal/token/xxx
POST /api/client/billing/paypal/capture
GET /api/client/billing/paypal/status?order_id=xxx
```

### Errors to check:
```javascript
Error capturing PayPal order: ...
Error checking PayPal order status: ...
```

## Network Tab Debugging

DevTools → Network tab → Filter "billing"

### Check each request:

#### 1. GET /paypal/token/{token}
- **Status:** 200 OK
- **Response:** `{"order_id": "...", "status": "pending", ...}`
- ❌ If 404: Token invalid or order not found

#### 2. POST /paypal/capture
- **Status:** 200 OK
- **Payload:** `{"order_id": "..."}`
- **Response:** `{"success": true, "message": "Order processed successfully", ...}`
- ❌ If 500: Check logs for error
- ❌ If 400/422: Check payload

#### 3. GET /paypal/status
- **Status:** 200 OK
- **Response:** `{"processed": true, "failed": false, ...}`
- ✅ If processed=true: Should redirect to success
- ❌ If failed=true: Will redirect to cancel
- ⏳ If pending=true: Still processing

## Testing in Sandbox Mode

### Prerequisites
1. PayPal Developer account
2. Sandbox app created
3. Sandbox credentials in Jexactyl
4. Test buyer account

### Test Flow
```
1. Login to Jexactyl
2. Select product
3. Click "Pay with PayPal"
4. Should redirect to sandbox.paypal.com
5. Login with test buyer account
6. Complete payment
7. Should redirect back to processing page
8. Should see "Processing order..." briefly
9. Should redirect to success page
10. Server should be created
```

### If It Fails
- Check each debugging step above
- Look for specific error in logs
- Verify sandbox credentials
- Try with different test account

## Advanced Debugging

### Enable Query Logging

Add to `AppServiceProvider::boot()`:
```php
\DB::listen(function ($query) {
    if (str_contains($query->sql, 'orders') || str_contains($query->sql, 'paypal')) {
        \Log::info('Query: ' . $query->sql, ['bindings' => $query->bindings]);
    }
});
```

### Test Capture Directly

Using curl:
```bash
curl -X POST https://your-domain.com/api/client/billing/paypal/capture \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"order_id": "PAYPAL_ORDER_ID"}'
```

### Check PayPal API Directly

```bash
# Get access token
curl -X POST https://api.sandbox.paypal.com/v1/oauth2/token \
  -u "CLIENT_ID:CLIENT_SECRET" \
  -d "grant_type=client_credentials"

# Get order status
curl -X GET https://api.sandbox.paypal.com/v2/checkout/orders/ORDER_ID \
  -H "Authorization: Bearer ACCESS_TOKEN"
```

## Next Steps

### If logs show capture succeeds but fulfillment fails:
→ Focus on server creation logic
→ Check node capacity
→ Review product configuration

### If logs show capture fails:
→ Check PayPal credentials
→ Verify PayPal API connectivity
→ Check if sandbox vs live mode matches

### If no logs at all:
→ Frontend issue
→ Check JavaScript console
→ Verify routes loaded
→ Clear browser cache

### If everything works in logs but UI shows cancelled:
→ Frontend polling issue
→ Check status check API response
→ Verify frontend logic in Processing.tsx

## Contact Support

If issue persists after all debugging:

**Provide:**
1. Complete log output from a test order
2. Network tab HAR export from browser
3. Database query results for the order
4. Screenshots of error messages
5. PayPal order ID from the attempt

This will help diagnose the specific issue.
