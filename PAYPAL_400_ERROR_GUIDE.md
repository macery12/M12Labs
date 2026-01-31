# PayPal 400 Error - Complete Debugging Guide

## Great Progress! 🎉

Your logs are working perfectly! We can now see exactly what's happening with the PayPal integration.

## What We Know from Your Logs

```
✅ Capture endpoint is being hit
✅ Order found in database (ID 517)
✅ Order status is pending
✅ PayPal order ID: 2LP85296RJ990125A
✅ Order is approved
✅ Capture attempt is made
❌ PayPal API returns status 400
```

Everything works correctly until PayPal processes the capture request!

## What HTTP 400 Means

**Status 400 = Bad Request**

This means PayPal received our request but rejected it because:
- Missing required field
- Invalid parameter value
- Request format is wrong
- Order state doesn't allow this action
- Authentication/permission issue

## The Solution: Enhanced Error Logging

We've just added enhanced logging that will show PayPal's EXACT error message.

### Before (what you saw):
```
PayPal order capture failed {"order_id":"2LP85296RJ990125A","status":400}
```

### After (what you'll see):
```
PayPal order capture failed {
    "order_id":"2LP85296RJ990125A",
    "status":400,
    "error_response":{
        "name":"UNPROCESSABLE_ENTITY",
        "message":"The requested action could not be performed",
        "details":[{
            "issue":"ORDER_ALREADY_CAPTURED",
            "description":"Order has already been captured"
        }]
    }
}
```

## Next Steps - Test Again

```bash
# 1. Pull latest code
git pull origin copilot/add-standalone-paypal-module

# 2. Clear caches
php artisan optimize:clear
sudo systemctl restart php8.1-fpm

# 3. Test PayPal checkout with a FRESH order
# Don't reuse order 517 - create a new one!

# 4. Watch logs
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log | grep -i paypal

# 5. Look for the "error_response" field in the log
```

## Common PayPal 400 Errors & Solutions

### 1. ORDER_ALREADY_CAPTURED

**Error:**
```json
{
    "name": "UNPROCESSABLE_ENTITY",
    "details": [{
        "issue": "ORDER_ALREADY_CAPTURED"
    }]
}
```

**What it means:**
- This order has already been captured
- You can't capture the same order twice

**Why it happens:**
- Testing the same order multiple times
- Capture succeeded but appeared to fail
- Order was captured in PayPal dashboard

**Solution:**
```bash
# Create a FRESH order for each test
# Don't reuse order ID 2LP85296RJ990125A
```

### 2. ORDER_NOT_APPROVED

**Error:**
```json
{
    "name": "UNPROCESSABLE_ENTITY",
    "details": [{
        "issue": "ORDER_NOT_APPROVED"
    }]
}
```

**What it means:**
- User didn't complete payment on PayPal
- Order is still in CREATED state, not APPROVED

**Why it happens:**
- User cancelled payment
- User closed browser before approving
- Never clicked "Pay Now" on PayPal

**Solution:**
- Make sure to click "Pay Now" on PayPal
- Complete the full payment flow
- Don't close browser before returning

### 3. INVALID_RESOURCE_ID

**Error:**
```json
{
    "name": "RESOURCE_NOT_FOUND",
    "details": [{
        "issue": "INVALID_RESOURCE_ID"
    }]
}
```

**What it means:**
- Order ID doesn't exist or is invalid

**Why it happens:**
- Wrong order ID format
- Order doesn't exist in PayPal
- Order was from wrong environment (live vs sandbox)

**Solution:**
- Verify order ID is correct
- Check you're using sandbox credentials with sandbox orders
- Create a fresh order

### 4. ORDER_EXPIRED

**Error:**
```json
{
    "name": "UNPROCESSABLE_ENTITY",
    "details": [{
        "issue": "ORDER_EXPIRED"
    }]
}
```

**What it means:**
- PayPal orders expire after 3 hours
- Can't capture an expired order

**Why it happens:**
- Created order, waited too long to pay
- Testing delays

**Solution:**
- Complete payment within 3 hours of creation
- Create fresh order if expired

### 5. INSTRUMENT_DECLINED

**Error:**
```json
{
    "name": "UNPROCESSABLE_ENTITY",
    "details": [{
        "issue": "INSTRUMENT_DECLINED"
    }]
}
```

**What it means:**
- PayPal declined the payment method
- Credit card or PayPal balance issue

**Why it happens (sandbox):**
- Using real credit card in sandbox
- Sandbox account has issues
- Invalid test card

**Solution (sandbox):**
- Use PayPal sandbox test accounts
- Use sandbox test credit cards
- Check sandbox account is funded

### 6. AMOUNT_MISMATCH

**Error:**
```json
{
    "name": "INVALID_REQUEST",
    "details": [{
        "issue": "AMOUNT_MISMATCH"
    }]
}
```

**What it means:**
- Amount in capture doesn't match order

**Why it happens:**
- Sending amount in capture request
- Amount doesn't match original order

**Solution:**
- Don't send amount parameter (captures full order)
- Or ensure amount exactly matches order total

### 7. MISSING_REQUIRED_PARAMETER

**Error:**
```json
{
    "name": "INVALID_REQUEST",
    "details": [{
        "field": "/some/field",
        "issue": "MISSING_REQUIRED_PARAMETER"
    }]
}
```

**What it means:**
- Required field is missing from request

**Solution:**
- Check error details for which field
- Add the missing field to request

### 8. INVALID_PARAMETER_VALUE

**Error:**
```json
{
    "name": "INVALID_REQUEST",
    "details": [{
        "field": "/some/field",
        "issue": "INVALID_PARAMETER_VALUE"
    }]
}
```

**What it means:**
- Field value is invalid

**Solution:**
- Check error details for which field
- Fix the value format

### 9. PERMISSION_DENIED

**Error:**
```json
{
    "name": "PERMISSION_DENIED",
    "message": "You do not have permission"
}
```

**What it means:**
- API credentials don't have permission
- Wrong account type

**Solution:**
- Verify API credentials
- Check app settings in PayPal dashboard
- Ensure capture permission is enabled

### 10. AUTHENTICATION_FAILURE

**Error:**
```json
{
    "name": "AUTHENTICATION_FAILURE",
    "message": "Authentication failed"
}
```

**What it means:**
- Access token is invalid or expired

**Why it happens:**
- Wrong client ID/secret
- Token expired
- Environment mismatch (sandbox vs live)

**Solution:**
- Verify credentials in admin panel
- Ensure using sandbox credentials for sandbox
- Check mode setting matches credentials

## Step-by-Step Debugging Process

### Step 1: Pull Enhanced Logging
```bash
git pull origin copilot/add-standalone-paypal-module
php artisan optimize:clear
sudo systemctl restart php8.1-fpm
```

### Step 2: Create Fresh Order
- Don't reuse old order
- Start completely fresh
- Use new sandbox buyer account if needed

### Step 3: Complete Payment
- Go through full PayPal flow
- Click "Pay Now" on PayPal
- Wait for redirect back

### Step 4: Check Logs
```bash
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log | grep -i paypal
```

### Step 5: Find Error Response
Look for log entry with `error_response`:
```
PayPal order capture failed {
    ...
    "error_response": { ... }
}
```

### Step 6: Match Error to This Guide
- Find the "name" or "issue" in error_response
- Match to section above
- Follow the solution

## PayPal Sandbox Testing Tips

### Use Sandbox Accounts
1. Go to developer.paypal.com
2. Create sandbox buyer account
3. Use that to test payments

### Don't Reuse Orders
- Each test should use fresh order
- Don't try to capture same order twice

### Stay Within 3-Hour Window
- Complete payment within 3 hours
- Orders expire after that

### Complete Full Flow
- Click through to PayPal
- Click "Pay Now"
- Wait for redirect back
- Don't close browser

### Check Sandbox Dashboard
- Login to sandbox business account
- Check if payment actually succeeded
- Verify order status

## Example Log Analysis

### Successful Capture (what we want to see):
```
[INFO] Attempting to capture PayPal payment {"paypal_order_id":"..."}
[INFO] PayPal capture result: COMPLETED
[INFO] Saved PayPal transaction details
[INFO] Order fulfillment completed successfully
```

### Failed Capture (what to analyze):
```
[ERROR] PayPal order capture failed {
    "order_id":"2LP85296RJ990125A",
    "status":400,
    "error_response":{
        "name":"UNPROCESSABLE_ENTITY",
        "message":"The requested action could not be performed",
        "details":[{
            "issue":"ORDER_ALREADY_CAPTURED",
            "description":"Order has already been captured. Please create a new order."
        }]
    }
}
```

**Analysis:**
- Issue: ORDER_ALREADY_CAPTURED
- Solution: Create fresh order (don't reuse 2LP85296RJ990125A)

## What to Share If Still Stuck

If the error persists after trying the solution:

1. **Complete error_response from log:**
```json
{
    "name": "...",
    "message": "...",
    "details": [...]
}
```

2. **Order ID:** e.g., 2LP85296RJ990125A

3. **Sandbox account info:**
- Using sandbox mode? Yes/No
- Business account email
- Buyer account email (if different)

4. **Steps taken:**
- Created new order
- Completed payment on PayPal
- Returned to site
- Saw error

5. **PayPal dashboard check:**
- Does order show as captured in PayPal?
- What status does PayPal show?

## Quick Reference

| HTTP Status | Meaning | Action |
|-------------|---------|--------|
| 200 | Success | ✅ Capture worked! |
| 400 | Bad Request | Check error_response |
| 401 | Unauthorized | Check credentials |
| 404 | Not Found | Check order ID |
| 422 | Unprocessable | Check order state |
| 500 | Server Error | PayPal issue, retry |

## The Bottom Line

We're VERY close to success! Everything is working except the final PayPal API call.

**The enhanced logging will show us:**
- Exactly what PayPal doesn't like
- Which specific field or condition is the problem
- The exact solution to apply

**Most likely it's one of:**
1. ✅ Reusing an already-captured order (solution: fresh order)
2. ✅ Order expired (solution: complete within 3 hours)
3. ✅ Payment not approved (solution: click Pay Now on PayPal)

One more test with the enhanced logging will reveal the exact issue and we'll fix it! 🎯
