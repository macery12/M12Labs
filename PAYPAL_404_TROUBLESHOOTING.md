# PayPal Update Order 404 Error - Troubleshooting Guide

## Error Details

**Request:**
```
PUT /api/client/billing/products/2/paypal/order
```

**Payload:**
```json
{
    "order_id": "0S519281A7256091N",
    "node_id": 1,
    "variables": [],
    "egg_id": 3,
    "name": "test"
}
```

**Response:**
```
404 Not Found
```

## Root Cause

The 404 error means Laravel cannot find the route. This is **NOT** because the route or controller is missing from the code - they both exist and are correctly configured. The issue is that the **server running the application doesn't have the updated routes loaded**.

## Verification

The code is correct:

### Route Definition
**File:** `routes/api-client.php` (line 112)
```php
Route::put('/products/{id}/paypal/order', [Client\Billing\PayPalCheckoutController::class, 'updateOrder']);
```

### Controller Method
**File:** `app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php` (line 118)
```php
public function updateOrder(Request $request, int $id): Response
{
    $product = Product::findOrFail($id);
    $paypalOrderId = $request->input('order_id');
    // ... rest of implementation
}
```

Both exist and are committed to the repository.

## Solution

Follow these steps **in order**:

### Step 1: Pull Latest Code

Make sure you have the latest code from the branch:

```bash
git fetch origin
git checkout copilot/add-standalone-paypal-module
git pull origin copilot/add-standalone-paypal-module
```

### Step 2: Verify Files Exist

Check that the controller file exists:

```bash
ls -l app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php
```

Should show the file (approximately 14KB).

### Step 3: Clear All Laravel Caches

This is the **most important step**. Laravel caches routes for performance, and the cache must be cleared after adding new routes:

```bash
php artisan optimize:clear
```

This command clears:
- Route cache
- Config cache
- View cache
- Event cache
- Compiled class cache

Alternatively, you can clear them individually:

```bash
php artisan route:clear
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

### Step 4: Verify Routes Are Loaded

List all routes to verify the PayPal routes are registered:

```bash
php artisan route:list --path=billing/products
```

You should see entries like:
```
PUT       api/client/billing/products/{id}/paypal/order ... updateOrder
POST      api/client/billing/products/{id}/paypal/order ... createOrder
```

### Step 5: Check Permissions

Ensure the web server has permission to read the files:

```bash
chmod -R 755 app/Http/Controllers/Api/Client/Billing/
```

### Step 6: Restart Web Server/PHP-FPM

After clearing caches, restart your web server:

**For PHP-FPM:**
```bash
sudo systemctl restart php8.1-fpm  # or your PHP version
```

**For Apache:**
```bash
sudo systemctl restart apache2
```

**For Nginx:**
```bash
sudo systemctl restart nginx
```

### Step 7: Check Laravel Logs

If still getting 404, check the Laravel logs for more details:

```bash
tail -f storage/logs/laravel.log
```

Then try the request again and watch for errors.

## Common Mistakes

### ❌ Not Clearing Route Cache
**Symptom:** Routes work locally but not on server
**Solution:** Always run `php artisan optimize:clear` after pulling new routes

### ❌ Wrong Branch
**Symptom:** Files are missing
**Solution:** Ensure you're on the `copilot/add-standalone-paypal-module` branch

### ❌ Not Restarting PHP-FPM
**Symptom:** Changes don't take effect
**Solution:** Restart PHP-FPM after clearing caches

### ❌ Composer Dependencies Not Updated
**Symptom:** Class not found errors
**Solution:** Run `composer install --no-dev --optimize-autoloader`

## Testing After Fix

Once you've completed the steps above, test the endpoint:

```bash
curl -X PUT \
  http://your-domain/api/client/billing/products/2/paypal/order \
  -H 'Authorization: Bearer YOUR_AUTH_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "order_id": "0S519281A7256091N",
    "node_id": 1,
    "variables": [],
    "egg_id": 3,
    "name": "test"
  }'
```

Expected responses:
- **204 No Content** - Success
- **401 Unauthorized** - Need to be logged in
- **404 Not Found** - Route still not found (repeat steps)
- **422 Validation Error** - Route works, but data is invalid

## Still Having Issues?

If you've completed all steps and still get 404:

1. **Check web server configuration**
   - Ensure all requests to `/api/*` are routed to Laravel
   - Check `.htaccess` or nginx config

2. **Check APP_DEBUG and APP_ENV**
   - Set `APP_DEBUG=true` in `.env` to see detailed errors
   - Check if routes are loaded in different environments

3. **Verify namespace**
   - Check `composer.json` autoload section
   - Run `composer dump-autoload`

4. **Check middleware**
   - The route requires authentication
   - Ensure you're sending a valid auth token

## Quick Fix Summary

```bash
# 1. Pull latest code
git pull origin copilot/add-standalone-paypal-module

# 2. Clear all caches (MOST IMPORTANT)
php artisan optimize:clear

# 3. Restart PHP
sudo systemctl restart php8.1-fpm

# 4. Test the endpoint
# Should now return 204 or a validation error (not 404)
```
