# PayPal PUT Route 404 Debugging Guide

## Issue

User reports PUT request to `/api/client/billing/products/{id}/paypal/order` returns 404, while POST to the same endpoint works fine.

## Code Verification ✅

All code is correct:

### Route Registration
**File:** `routes/api-client.php` lines 116-117
```php
Route::post('/products/{id}/paypal/order', [Client\Billing\PayPalCheckoutController::class, 'createOrder']);
Route::put('/products/{id}/paypal/order', [Client\Billing\PayPalCheckoutController::class, 'updateOrder']);
```
✅ Both routes registered identically to Mollie/Stripe patterns

### Controller Method
**File:** `app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php` line 118
```php
public function updateOrder(Request $request, int $id): Response
```
✅ Method exists with correct signature

### Frontend API Call
**File:** `resources/scripts/api/routes/account/billing/orders/paypal.ts` line 70
```php
http.put(`/api/client/billing/products/${id}/paypal/order`, { ...data })
```
✅ Frontend correctly uses PUT method

## Why POST Works But PUT Doesn't

This is a **deployment/cache issue**, not a code issue. Here's why:

### Laravel Route Caching

1. Laravel caches routes for performance
2. When routes file is updated, cache contains OLD routes
3. POST route may have been in the cache already
4. PUT route is NEW and not in cached routes
5. Laravel returns 404 for routes not in cache

### Verification Steps

Run these commands on your server:

```bash
# 1. Check if route cache exists
ls -la bootstrap/cache/routes-*.php

# If files exist, that's your problem!
```

```bash
# 2. Clear route cache
php artisan route:clear

# 3. Clear ALL caches
php artisan optimize:clear

# 4. Restart PHP-FPM
sudo systemctl restart php8.1-fpm
```

```bash
# 5. Verify routes are loaded
php artisan route:list --path=billing/products | grep paypal

# You should see BOTH:
# POST   api/client/billing/products/{id}/paypal/order
# PUT    api/client/billing/products/{id}/paypal/order
```

### Web Server Configuration

Some web servers (Apache/Nginx) may not forward PUT requests correctly:

**Check Nginx config:**
```nginx
# In your site config, ensure this is present:
location /api {
    try_files $uri $uri/ /index.php?$query_string;
}

# NOT:
location /api {
    try_files $uri /index.php?$query_string;  # Missing $uri/
}
```

**Check Apache .htaccess:**
```apache
# Ensure PUT is not blocked:
<Limit GET POST PUT DELETE>
    Allow from all
</Limit>
```

### Testing PUT Directly

Test if PUT works at all on your server:

```bash
# Create a test route (temporarily)
# Add to routes/api-client.php:
Route::put('/test-put', function() {
    return response()->json(['success' => true]);
});

# Clear cache
php artisan route:clear

# Test with curl
curl -X PUT https://your-domain.com/api/client/test-put \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN"

# Should return: {"success":true}
```

## Common Causes & Solutions

### 1. Route Cache Not Cleared (Most Likely)

**Symptom:** POST works, PUT doesn't  
**Cause:** Old routes cached without PUT  
**Solution:**
```bash
php artisan optimize:clear
sudo systemctl restart php8.1-fpm
```

### 2. Web Server Not Forwarding PUT

**Symptom:** All PUT requests fail with 404  
**Cause:** Nginx/Apache config issue  
**Solution:** Fix web server config (see above)

### 3. Middleware Blocking PUT

**Symptom:** PUT returns 404 or 405  
**Cause:** Middleware or CSRF protection  
**Solution:** Check middleware in routes file - PayPal routes should be in same group as Mollie

### 4. Method Not Allowed vs 404

**Important distinction:**
- **404** = Route not found (cache issue)
- **405** = Route found but method not allowed (different issue)

If you're getting 405 instead of 404, that's a different problem.

## Debugging Steps

### Step 1: Enable Laravel Debug Mode

```bash
# In .env file:
APP_DEBUG=true
APP_ENV=local
```

Then try the PUT request again. You'll get a detailed error page.

### Step 2: Check Laravel Logs

```bash
tail -100 storage/logs/laravel.log
```

Look for:
- Route not found errors
- Middleware errors
- Controller errors

### Step 3: Check Web Server Logs

```bash
# Nginx
tail -100 /var/log/nginx/error.log

# Apache
tail -100 /var/log/apache2/error.log
```

### Step 4: Test Route Registration

```bash
# List all billing routes
php artisan route:list --path=billing

# Search specifically for paypal
php artisan route:list | grep paypal

# Check if PUT is there
php artisan route:list | grep "PUT.*paypal.*order"
```

### Step 5: Compare with Working Mollie Routes

If Mollie PUT works but PayPal PUT doesn't:

```bash
# Check both routes
php artisan route:list | grep "PUT.*mollie\|PUT.*paypal"

# Should see:
# PUT  api/client/billing/products/{id}/mollie/payment
# PUT  api/client/billing/products/{id}/paypal/order
```

If Mollie shows up but PayPal doesn't = cache issue!

## The ArgumentCountError

The error you mentioned:
```
ArgumentCountError: Too few arguments to function ApplicationApiController::__construct()
```

This is a **separate issue** in `ResourceUtilizationController` and is NOT related to PayPal routes.

### What's Happening

The error shows controller inheritance issue:
1. `ResourceUtilizationController` extends `ClientApiController`
2. `ClientApiController` extends `ApplicationApiController`
3. `ApplicationApiController` has a complex constructor with dependencies

This is happening because Laravel is trying to instantiate a controller but dependency injection is failing.

### Why It Might Be Related

If dependency injection is broken:
- Laravel can't instantiate controllers properly
- ALL routes might fail, not just PayPal
- 404s and 500s would be intermittent

### Solution

This is likely a **broader system issue**. Check:

1. **Composer autoload:**
```bash
composer dump-autoload --optimize
```

2. **Config cache:**
```bash
php artisan config:clear
```

3. **Application cache:**
```bash
php artisan cache:clear
```

4. **Service providers:**
Check if all service providers are registered in `config/app.php`

## Final Checklist

Before concluding there's a code bug, verify:

- [ ] Route cache cleared: `php artisan route:clear`
- [ ] Config cache cleared: `php artisan config:clear`
- [ ] Application cache cleared: `php artisan cache:clear`
- [ ] Optimized cache cleared: `php artisan optimize:clear`
- [ ] PHP-FPM restarted: `sudo systemctl restart php8.1-fpm`
- [ ] Composer autoload refreshed: `composer dump-autoload`
- [ ] Routes show up in `php artisan route:list`
- [ ] Web server can handle PUT requests (test route)
- [ ] No middleware blocking PUT
- [ ] Laravel logs checked for actual error

## Expected vs Actual

### What SHOULD Happen

1. Frontend calls: `PUT /api/client/billing/products/2/paypal/order`
2. Laravel routes to: `PayPalCheckoutController@updateOrder`
3. Controller executes, updates order
4. Returns: 204 No Content
5. Frontend redirects to PayPal

### What's ACTUALLY Happening (Based on Report)

1. Frontend calls: `PUT /api/client/billing/products/2/paypal/order`
2. Laravel returns: 404 Not Found
3. Route not found in cached routes
4. Frontend shows error

## Solution Summary

**99% chance this is a cache issue.** Run:

```bash
# On your server
cd /var/www/jexactyl
php artisan optimize:clear
composer dump-autoload --optimize
sudo systemctl restart php8.1-fpm

# Verify
php artisan route:list | grep "PUT.*paypal"
```

If you still get 404 after this, then we have a different problem.

## Need More Help?

If the issue persists after:
1. Clearing all caches
2. Restarting PHP
3. Verifying routes are loaded
4. Testing PUT works on server

Then provide:
1. Output of `php artisan route:list | grep paypal`
2. Laravel log excerpt showing the 404
3. Web server error logs
4. Result of testing a simple PUT route
5. Output of `php artisan --version`
6. Output of `composer show | grep laravel`
