# PayPal Integration - Complete Setup and Troubleshooting Guide

## Overview

This guide covers the complete setup of PayPal standalone integration, including webhook configuration, troubleshooting common issues, and deployment steps.

## Prerequisites

1. PayPal Business account
2. Access to PayPal Developer Dashboard
3. Jexactyl admin access
4. Server with public URL (for webhooks)

## Step 1: Create PayPal App

### 1.1 Access Developer Dashboard

**For Sandbox (Testing):**
1. Go to https://developer.paypal.com/dashboard/applications/sandbox
2. Click "Create App"
3. Enter app name (e.g., "Jexactyl Sandbox")
4. Select "Merchant" as app type
5. Click "Create App"

**For Live (Production):**
1. Go to https://developer.paypal.com/dashboard/applications/live
2. Click "Create App"
3. Enter app name (e.g., "Jexactyl Production")
4. Select "Merchant" as app type
5. Click "Create App"

### 1.2 Get API Credentials

After creating the app:
1. Copy the **Client ID** (shown in app details)
2. Click "Show" next to **Secret** and copy it
3. Keep both values safe - you'll need them in Jexactyl

## Step 2: Configure Jexactyl

### 2.1 Enable PayPal Integration

1. Log in to Jexactyl admin panel
2. Go to **Admin → Billing → Integrations**
3. Find the **PayPal** tab
4. Toggle "Enable PayPal" to ON

### 2.2 Add API Credentials

1. Click "Add PayPal Credentials" button
2. Select environment mode:
   - **Sandbox** for testing with fake money
   - **Live** for real payments
3. Paste your **Client ID**
4. Paste your **Client Secret**
5. Click "Save PayPal Credentials"
6. **Important:** Page will reload - this is normal

### 2.3 Copy Webhook URL

After saving credentials:
1. Go back to **Admin → Billing → Integrations → PayPal**
2. Find the "PayPal Webhook URL" box
3. Click "Copy" button to copy the webhook URL
4. It should look like: `https://your-domain.com/api/client/billing/paypal/webhook`

## Step 3: Configure PayPal Webhook (CRITICAL!)

⚠️ **This step is REQUIRED for payments to work reliably!**

### 3.1 Add Webhook in PayPal

1. Go back to PayPal Developer Dashboard
2. Select your app (sandbox or live)
3. Scroll down to "Webhooks" section
4. Click "Add Webhook"

### 3.2 Configure Webhook

1. **Webhook URL:** Paste the URL you copied from Jexactyl
2. **Event types:** Click "Select events" and choose:
   - ✅ `Checkout order completed` (CHECKOUT.ORDER.COMPLETED)
   - ✅ `Payment capture completed` (PAYMENT.CAPTURE.COMPLETED)
3. Click "Save"

### 3.3 Verify Webhook

After saving:
1. PayPal will show your webhook in the list
2. Status should show as "Active"
3. If using sandbox, you can test with sandbox simulator

## Step 4: Deploy Code (If Updating)

If you're updating an existing installation:

### 4.1 Pull Latest Code

```bash
cd /var/www/jexactyl  # or your installation directory
git fetch origin
git checkout copilot/add-standalone-paypal-module
git pull origin copilot/add-standalone-paypal-module
```

### 4.2 Clear All Caches (IMPORTANT!)

```bash
# Clear Laravel caches
php artisan optimize:clear

# Or individually:
php artisan route:clear
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

### 4.3 Install Dependencies (if needed)

```bash
composer install --no-dev --optimize-autoloader
```

### 4.4 Run Database Migrations

```bash
php artisan migrate --force
```

### 4.5 Restart Services

```bash
# Restart PHP-FPM
sudo systemctl restart php8.1-fpm  # adjust version as needed

# Restart web server
sudo systemctl restart nginx  # or apache2
```

### 4.6 Build Frontend (if changed)

```bash
npm install
npm run build
```

## Step 5: Test the Integration

### 5.1 Sandbox Testing

1. Go to your Jexactyl storefront
2. Select a product
3. Choose PayPal as payment method
4. Click "Pay with PayPal"
5. You should be redirected to PayPal sandbox
6. Log in with sandbox buyer account
7. Complete the payment
8. You should be redirected back to Jexactyl
9. Server should be created automatically

### 5.2 Check Logs

Monitor logs during testing:

```bash
tail -f storage/logs/laravel.log
```

Look for:
- ✅ `PayPal order created`
- ✅ `PayPal webhook received`
- ✅ `PayPal order completed`
- ✅ `Created new server`

## Troubleshooting

### Issue: PUT Request Returns 404

**Symptoms:**
- POST to create order works (200 OK)
- PUT to update order fails (404)
- Never redirected to PayPal

**Cause:**
Routes not loaded due to cache or deployment issue.

**Solution:**
```bash
# Clear route cache
php artisan route:clear

# Verify route exists
php artisan route:list --path=billing/products | grep paypal

# Should show:
# POST   api/client/billing/products/{id}/paypal/order
# PUT    api/client/billing/products/{id}/paypal/order
```

If routes don't appear:
1. Make sure you pulled latest code
2. Check file exists: `app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php`
3. Restart PHP-FPM: `sudo systemctl restart php8.1-fpm`

### Issue: Not Redirected to PayPal

**Symptoms:**
- Form submits but nothing happens
- No redirect to PayPal

**Causes:**
1. JavaScript error in console
2. PUT request failing (see above)
3. Button disabled due to validation

**Solutions:**

**Check browser console (F12):**
```
Look for errors in Console tab
Check Network tab for failed requests
```

**Verify form fields filled:**
- Location selected?
- Server name entered?
- Both fields required before PayPal button enables

**Check updatePayPalOrder API call:**
- Should succeed before redirect
- If 404, see "PUT Request Returns 404" above

### Issue: Payment Completes but Server Not Created

**Symptoms:**
- PayPal payment succeeds
- Money debited
- No server created in Jexactyl

**Cause:**
Webhook not configured or not working.

**Solutions:**

**1. Verify webhook configured in PayPal:**
- Go to PayPal app → Webhooks
- Check URL is correct
- Check events are subscribed

**2. Check webhook logs:**
```bash
# Watch for incoming webhooks
tail -f storage/logs/laravel.log | grep "PayPal webhook"
```

**3. Test webhook manually:**
```bash
# From PayPal Developer Dashboard
# Go to Webhooks → Your webhook → Send Test
```

**4. Common webhook issues:**
- Webhook URL not accessible (firewall blocking)
- SSL certificate invalid
- Wrong URL (http vs https)

### Issue: Webhook Returns 500 Error

**Symptoms:**
- PayPal webhook fails
- 500 error in PayPal webhook logs

**Solutions:**

**Check Laravel logs:**
```bash
tail -100 storage/logs/laravel.log
```

**Common causes:**
- Database connection failed
- PayPal API credentials invalid
- Order not found (timing issue)

**Fix:**
- Ensure database is running
- Verify API credentials are correct for mode (sandbox vs live)
- Check order was created before webhook called

### Issue: Webhooks Work in Sandbox but Not Live

**Cause:**
Using sandbox credentials in live mode or vice versa.

**Solution:**
1. In Jexactyl admin, verify mode matches credentials:
   - Sandbox credentials → Sandbox mode
   - Live credentials → Live mode
2. Create separate webhooks for sandbox and live
3. Use correct webhook URL for each environment

## Security Best Practices

### 1. Use HTTPS

Webhooks MUST use HTTPS in production:
```bash
# Verify SSL certificate
curl -I https://your-domain.com/api/client/billing/paypal/webhook
```

### 2. Monitor Webhook Logs

Regularly check webhook activity:
```bash
grep "PayPal webhook" storage/logs/laravel.log
```

### 3. Test in Sandbox First

Always test in sandbox before going live:
1. Use sandbox credentials
2. Test complete purchase flow
3. Verify webhooks work
4. Then switch to live mode

### 4. Keep Credentials Secure

- Never commit credentials to git
- Store in database (not .env for PayPal)
- Use different credentials for sandbox vs live
- Rotate credentials periodically

## Webhook Event Types

PayPal sends these events (subscribe to both):

### CHECKOUT.ORDER.COMPLETED
Triggered when customer completes checkout and order is created.
```json
{
  "event_type": "CHECKOUT.ORDER.COMPLETED",
  "resource": {
    "id": "ORDER_ID",
    "status": "COMPLETED"
  }
}
```

### PAYMENT.CAPTURE.COMPLETED
Triggered when payment is captured (money received).
```json
{
  "event_type": "PAYMENT.CAPTURE.COMPLETED",
  "resource": {
    "id": "CAPTURE_ID",
    "status": "COMPLETED"
  }
}
```

## Advanced Configuration

### Custom Return URL

By default, users return to `/account/billing/processing?processor=paypal`.

This is defined in `PayPalPaymentButton.tsx`:
```typescript
const returnUrl = window.location.origin + '/account/billing/processing?processor=paypal';
```

### Webhook Retry Logic

PayPal will retry webhooks if your endpoint returns non-200 status:
- Returns 200 even on errors (prevents infinite retries)
- Logs all errors for manual review
- Idempotent processing prevents duplicates

### Database Tables

PayPal integration uses:
- `orders` table - Order records
- `paypal_order_id` column - Links to PayPal order

Check order status:
```sql
SELECT id, status, paypal_order_id, user_id, created_at 
FROM orders 
WHERE payment_processor = 'paypal' 
ORDER BY created_at DESC 
LIMIT 10;
```

## Support

If you encounter issues not covered here:

1. Check Laravel logs: `storage/logs/laravel.log`
2. Check PayPal webhook logs in Developer Dashboard
3. Enable debug mode: `APP_DEBUG=true` in `.env`
4. Check GitHub issues for similar problems
5. Create detailed issue with logs and steps to reproduce

## Checklist for Production

Before enabling PayPal in production:

- [ ] PayPal live app created
- [ ] Live credentials added to Jexactyl
- [ ] Mode set to "Live" (not sandbox)
- [ ] Webhook URL configured in PayPal
- [ ] Webhook events subscribed (both events)
- [ ] Webhook tested and working
- [ ] SSL certificate valid
- [ ] Test purchase completed successfully
- [ ] Server created automatically
- [ ] Payment reflected in PayPal account
- [ ] Logs showing successful webhook processing

## Summary

The PayPal integration is now complete with:
- ✅ Direct PayPal checkout
- ✅ Webhook support for reliable payment processing
- ✅ Sandbox and live mode support
- ✅ Comprehensive error handling
- ✅ Admin UI for easy setup
- ✅ Complete documentation

For most "it's not working" issues, the solution is:
1. Clear Laravel caches: `php artisan optimize:clear`
2. Verify webhook configured in PayPal
3. Check logs: `tail -f storage/logs/laravel.log`
