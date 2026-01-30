# PayPal Settings Persistence Issue - Resolution

## Problem Statement

The PayPal integration settings were not persisting to the database. Specifically:

1. **Enable/Disable Toggle**: When enabling PayPal via the admin panel, the request succeeded (PUT 204), but after page refresh the toggle reverted to disabled
2. **Credentials**: Client ID and Client Secret would have the same issue when attempting to save them

## Root Cause

The Laravel application uses a `SettingsServiceProvider` that bridges database-stored settings with the application's configuration system. This provider has a whitelist array (`$keys`) that defines which settings should be loaded from the database.

**The PayPal integration keys were not included in this whitelist.**

### Settings Flow

1. **Save Flow** (Working ✅):
   ```
   Frontend → API Request → BillingController → SettingsRepository → Database
   Key: "integrations:paypal:enabled"
   Saved as: "settings::modules:billing:integrations:paypal:enabled"
   ```

2. **Load Flow** (Broken ❌):
   ```
   Application Boot → SettingsServiceProvider → Check $keys array
   If key NOT in array → Skip loading from database
   Use default from config file instead
   Result: Setting appears as if never saved
   ```

## Solution

Added the missing PayPal keys to the `SettingsServiceProvider::$keys` whitelist array:

```php
// In app/Providers/SettingsServiceProvider.php
protected array $keys = [
    // ... existing keys ...
    'modules:billing:integrations:stripe:enabled',
    'modules:billing:integrations:mollie:enabled',
    'modules:billing:integrations:paypal:enabled',        // ← NEW
    'modules:billing:paypal_standalone:client_id',        // ← NEW
    'modules:billing:paypal_standalone:client_secret',    // ← NEW
    'modules:billing:paypal_standalone:mode',             // ← NEW
    // ... other keys ...
];
```

## Technical Details

### Key Transformation

The system uses a specific naming convention:

| Frontend Key | Database Key | Config Key |
|-------------|--------------|------------|
| `integrations:paypal:enabled` | `settings::modules:billing:integrations:paypal:enabled` | `modules.billing.integrations.paypal.enabled` |
| `paypal_standalone:client_id` | `settings::modules:billing:paypal_standalone:client_id` | `modules.billing.paypal_standalone.client_id` |
| `paypal_standalone:client_secret` | `settings::modules:billing:paypal_standalone:client_secret` | `modules.billing.paypal_standalone.client_secret` |
| `paypal_standalone:mode` | `settings::modules:billing:paypal_standalone:mode` | `modules.billing.paypal_standalone.mode` |

### Why This Happened

When the PayPal standalone integration was implemented, all the following components were correctly created:

✅ Frontend UI components (PayPalSettings.tsx, SetupPayPalKeys.tsx)  
✅ Frontend API calls (updateSettings with correct keys)  
✅ Backend API endpoint (BillingController::settings)  
✅ Database save logic (SettingsRepository)  
✅ Config reading logic (EverestComposer, PaymentProcessorConfigService)  

❌ **But the SettingsServiceProvider whitelist was not updated**

This created a "silent failure" where:
- Saves appeared to work (HTTP 204 response)
- Data was in the database
- But was never loaded back into the application

## Verification

After the fix, the complete flow works:

1. Admin enables PayPal in UI
2. Frontend sends: `PUT /api/application/billing/settings` with `{"key":"integrations:paypal:enabled","value":true}`
3. Backend saves: `settings::modules:billing:integrations:paypal:enabled` = `"true"` to database
4. Returns: `HTTP 204 No Content`
5. Page refreshes
6. `SettingsServiceProvider` runs on application boot
7. Finds key in `$keys` array ✅
8. Loads value from database: `"true"`
9. Converts string `"true"` to boolean `true` (via `$map` array)
10. Sets config: `modules.billing.integrations.paypal.enabled` = `true`
11. Frontend reads config and displays PayPal as enabled ✅

## Similar Issues in Other Integrations

Both Stripe and Mollie had their integration keys properly registered:

```php
'modules:billing:integrations:stripe:enabled',  // Line 67
'modules:billing:integrations:mollie:enabled',  // Line 68
```

PayPal should have been added at the same time but was missed.

## Prevention

When adding new module settings in the future:

1. **Add to config file** (`config/modules/*.php`)
2. **Add to SettingsServiceProvider** (`app/Providers/SettingsServiceProvider.php`)
3. **Update EverestComposer** if needed for frontend (`app/Http/ViewComposers/EverestComposer.php`)
4. **Test the complete flow**: Save → Refresh → Verify persistence

## Files Modified

- `app/Providers/SettingsServiceProvider.php` - Added 4 PayPal keys to whitelist

## Impact

✅ PayPal enable/disable toggle now persists  
✅ PayPal Client ID now persists  
✅ PayPal Client Secret now persists  
✅ PayPal Mode (sandbox/live) now persists  
✅ No breaking changes  
✅ No database migration needed  
✅ No frontend changes needed  

The fix is minimal, surgical, and follows the existing pattern used by Stripe and Mollie integrations.
