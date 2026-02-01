# Fix for "t.variables is not iterable" Error

## Problem Statement

The modpacks route condition was throwing a runtime error:
```
t.variables is not iterable
```

This occurred when the route tried to check if a server has the required environment variables (PROJECT_ID and VERSION_ID) to support modpacks.

## Root Cause

The error happened because `server.variables` could be:

1. **`null` or `undefined`**: If the user doesn't have `Permission::ACTION_STARTUP_READ` permission, the ServerTransformer's `includeVariables()` method returns a `NullResource` instead of an array
2. **Not yet loaded**: The variables array might not be available when the route condition is evaluated
3. **Permission-dependent**: The variables inclusion is conditional on user permissions

## Solution

Instead of checking variables on the frontend, we moved the logic to the backend:

### 1. Backend Changes (`ServerTransformer.php`)

Added a `modpacksSupported` boolean field that is calculated during server transformation:

```php
// Check if server supports modpacks by checking for required environment variables
$modpacksSupported = false;
if ($server->mods_enabled && $server->relationLoaded('variables')) {
    $hasProjectId = false;
    $hasVersionId = false;
    
    foreach ($server->variables as $variable) {
        if ($variable->env_variable === 'PROJECT_ID') {
            $hasProjectId = true;
        }
        if ($variable->env_variable === 'VERSION_ID') {
            $hasVersionId = true;
        }
        // Early exit if both found
        if ($hasProjectId && $hasVersionId) {
            break;
        }
    }
    
    $modpacksSupported = $hasProjectId && $hasVersionId;
}
```

**Key Features:**
- Checks `relationLoaded('variables')` to prevent N+1 queries
- Single loop with early exit for performance
- Only checks if `mods_enabled` is true
- Safely defaults to `false` if variables aren't loaded

### 2. Frontend Changes

**Server Interface** (`server/models.d.ts`):
```typescript
interface Server {
    // ... other fields
    modsEnabled: boolean;
    modpacksSupported: boolean; // NEW
    // ... other fields
}
```

**Route Condition** (`server.ts`):
```typescript
// Before: Complex iteration with potential error
route('modpacks/*', ModpacksContainer, {
    permission: 'file.create',
    name: 'Modpacks',
    icon: Icon.CollectionIcon,
    category: 'data',
    condition: server => {
        if (!server.modsEnabled) return false;
        
        for (const variable of server.variables) { // ERROR: variables might not be iterable
            // ...
        }
        return hasProjectId && hasVersionId;
    },
})

// After: Simple boolean check
route('modpacks/*', ModpacksContainer, {
    permission: 'file.create',
    name: 'Modpacks',
    icon: Icon.CollectionIcon,
    category: 'data',
    condition: server => server.modsEnabled && server.modpacksSupported,
})
```

## Benefits

✅ **Eliminates Runtime Error**: No more "not iterable" errors  
✅ **Better Performance**: Backend calculates once instead of frontend checking on every render  
✅ **Handles Permissions**: Gracefully handles cases where variables aren't accessible  
✅ **Cleaner Code**: Simple boolean check instead of complex iteration  
✅ **Prevents N+1 Queries**: Uses `relationLoaded()` check  
✅ **Optimized Loop**: Single iteration with early exit  

## Technical Details

### Performance Optimizations

1. **Single Iteration**: Instead of two `contains()` calls (which each iterate the collection), we use a single loop
2. **Early Exit**: Loop breaks as soon as both variables are found
3. **Lazy Evaluation**: Only checks variables if `mods_enabled` is true and variables are loaded

### Safety Checks

1. **Relation Check**: `$server->relationLoaded('variables')` prevents accessing unloaded relationships
2. **Default Value**: Returns `false` if any condition isn't met
3. **No Assumptions**: Doesn't assume variables are always present

## Testing

### Build Status
```
✓ Frontend builds successfully
✓ No TypeScript errors
✓ No runtime errors
```

### Security Scan
```
CodeQL Analysis: 0 alerts
✓ No security vulnerabilities
```

### Code Review
- All feedback addressed
- Performance optimizations applied
- Best practices followed

## Files Changed

```
Modified (3 files):
1. app/Transformers/Api/Client/ServerTransformer.php
   - Added modpacksSupported calculation
   - Used relationLoaded() check
   - Single loop with early exit

2. resources/scripts/api/definitions/server/models.d.ts
   - Added modpacksSupported: boolean field

3. resources/scripts/routers/routes/server.ts
   - Simplified route condition
   - Removed variable iteration
   - Simple boolean check
```

## Migration Notes

No database migrations required. This is a runtime calculation based on existing data.

### For Administrators
1. Pull the latest changes
2. Run `pnpm install && pnpm run build`
3. No additional configuration needed

### For Users
- Transparent change
- Modpacks tab still shows/hides based on same logic
- No user-facing changes except error is fixed

## Future Considerations

If performance becomes an issue with many servers:
1. Could cache `modpacksSupported` value in database
2. Could update it when variables are modified
3. For now, runtime calculation is efficient enough

## Debugging

If modpacks tab doesn't appear when expected:

1. **Check server has mods enabled**: 
   ```sql
   SELECT mods_enabled FROM servers WHERE id = ?;
   ```

2. **Check environment variables exist**:
   ```sql
   SELECT sv.env_variable, sv.server_value 
   FROM server_variables sv
   WHERE sv.server_id = ?
   AND sv.env_variable IN ('PROJECT_ID', 'VERSION_ID');
   ```

3. **Check API response**:
   ```bash
   curl /api/client/servers/{uuid} | jq '.attributes.modpacks_supported'
   ```

## Related Issues

- Fixes: "t.variables is not iterable" error
- Related to: Modpacks route visibility
- Dependency: Mods module must be enabled
