# Modpacks Tab Always Visible with API-Based Support Check

## Overview
This document describes the implementation of always showing the modpacks tab (when mods are enabled) and checking for modpack support via API call rather than backend calculation.

## Problem Statement
Previously, the modpacks tab would only appear if the backend determined the server had the required environment variables (PROJECT_ID and VERSION_ID). If these variables weren't present, the tab was completely hidden, leaving users confused about why modpacks weren't available.

## Solution
The modpacks tab now always appears when mods are enabled. The component fetches startup variables via API and:
- Shows a loading state while checking
- Displays a clear message if modpacks aren't supported
- Shows the modpacks browser if supported

## Implementation Details

### 1. Route Condition Change

**File:** `resources/scripts/routers/routes/server.ts`

**Before:**
```typescript
condition: server => server.modsEnabled && server.modpacksSupported
```

**After:**
```typescript
condition: server => server.modsEnabled
```

The tab now only requires mods to be enabled, not the backend-calculated `modpacksSupported` flag.

### 2. ModpacksContainer Component

**File:** `resources/scripts/components/server/modpacks/ModpacksContainer.tsx`

#### Constants
```typescript
const REQUIRED_MODPACK_VARIABLES = ['PROJECT_ID', 'VERSION_ID'] as const;
```

#### API Call
Uses existing `getServerStartup()` hook to fetch variables:
```typescript
const { data: startupData, error: startupError } = getServerStartup(
    uuid,
    shouldFetchStartup ? undefined : { invocation: '', variables: [], dockerImages: {} },
    { revalidateOnFocus: false, revalidateOnReconnect: false }
);
```

#### Support Detection
```typescript
const hasAllVariables = REQUIRED_MODPACK_VARIABLES.every(
    varName => startupData.variables.some(v => v.envVariable === varName)
);
```

#### User Experience States

**1. Loading State:**
```typescript
if (checkingSupport) {
    return (
        <PageContentBlock title={'Modpacks Browser'} ...>
            <div css={tw`flex justify-center py-16`}>
                <Spinner size={'large'} />
            </div>
        </PageContentBlock>
    );
}
```

**2. Unsupported State:**
```typescript
if (!modpacksSupported) {
    return (
        <PageContentBlock title={'Modpacks Browser'} ...>
            <div css={tw`text-center py-16`}>
                <p css={tw`text-neutral-300 text-lg mb-4`}>
                    Your server does not have modpack support.
                </p>
                <p css={tw`text-neutral-400 text-sm mb-2`}>
                    This server is not configured with the required environment variables...
                </p>
                <p css={tw`text-neutral-400 text-xs`}>
                    Please contact an administrator to change your server to use a modpack-compatible egg...
                </p>
            </div>
        </PageContentBlock>
    );
}
```

**3. Supported State:**
Shows the normal modpacks browser interface with search and listing.

## API Endpoint Used

**Endpoint:** `/api/client/servers/{uuid}/startup`

**Response Structure:**
```json
{
    "object": "list",
    "data": [
        {
            "object": "egg_variable",
            "attributes": {
                "name": "Modpack Project ID",
                "env_variable": "PROJECT_ID",
                "server_value": "1298402",
                ...
            }
        },
        {
            "object": "egg_variable",
            "attributes": {
                "name": "Modpack File ID",
                "env_variable": "VERSION_ID",
                "server_value": "latest",
                ...
            }
        }
    ],
    "meta": {
        "startup_command": "...",
        "docker_images": {...}
    }
}
```

## Required Environment Variables

For a server to support modpacks, it must have these environment variables:

1. **PROJECT_ID**
   - CurseForge project/modpack ID
   - Example: `1298402`

2. **VERSION_ID**
   - Specific version/file ID or "latest"
   - Example: `latest` or specific file ID

These are typically provided by the CurseForge Generic egg or similar modpack-compatible eggs.

## Benefits

### User Experience
✅ **Always visible:** Modpacks tab appears whenever mods are enabled  
✅ **Clear messaging:** Users understand why modpacks aren't available  
✅ **Actionable:** Message tells users to contact admin  

### Technical
✅ **No backend calculation:** Removed need for `modpacksSupported` flag in transformer  
✅ **Uses existing API:** Leverages `/startup` endpoint already used elsewhere  
✅ **Conditional loading:** Only fetches startup data when needed  
✅ **Maintainable:** Variable names extracted to constants  

### Performance
✅ **Lazy check:** Only checks support when tab is accessed  
✅ **No unnecessary calls:** Doesn't fetch if mods disabled  
✅ **Cached data:** SWR caching reduces redundant requests  

## Code Quality Improvements

### From Code Review

1. **Conditional API calls:**
   ```typescript
   const shouldFetchStartup = modsEnabled && globalModsEnabled;
   ```
   Only fetches startup data when actually needed.

2. **Named constants:**
   ```typescript
   const REQUIRED_MODPACK_VARIABLES = ['PROJECT_ID', 'VERSION_ID'] as const;
   ```
   Makes requirements explicit and maintainable.

3. **Better logic:**
   ```typescript
   const hasAllVariables = REQUIRED_MODPACK_VARIABLES.every(...)
   ```
   Clear, declarative check for all required variables.

## Migration Notes

### For Developers
- Can remove `modpacksSupported` from `ServerTransformer.php` (optional cleanup)
- Can remove `modpacksSupported` from Server interface (optional cleanup)
- Frontend now owns the modpack support detection logic

### For Users
- **No changes required**
- Modpacks tab now visible even if server doesn't support modpacks
- Clear message explains what's needed for modpack support

## Testing

### Build Status
```
✓ Frontend builds successfully
✓ No TypeScript errors
✓ No compilation warnings
```

### Security Scan
```
CodeQL Analysis: 0 alerts
✓ No security vulnerabilities
```

### Code Review
- All feedback addressed
- Constants extracted
- API calls optimized
- Logic improved

## Future Enhancements

Potential improvements:
1. Cache the support check result to avoid repeated API calls
2. Add a "Learn More" link to documentation about modpack eggs
3. Show which specific variables are missing
4. Add admin quick-action to switch to modpack egg

## Troubleshooting

### Modpacks Tab Not Appearing
**Check:**
1. Is mods module enabled globally?
2. Is mods enabled for this server?

### "Does Not Have Modpack Support" Message
**Solution:**
1. Server needs to use a modpack-compatible egg (e.g., CurseForge Generic)
2. Contact administrator to change server egg
3. Ensure PROJECT_ID and VERSION_ID variables exist in egg

### Infinite Loading
**Possible Causes:**
1. Startup API endpoint not responding
2. Network issues
3. Permission issues accessing startup data

**Debug:**
```bash
# Check API response
curl /api/client/servers/{uuid}/startup

# Check browser console for errors
# Should see SWR request to /startup endpoint
```

## Related Files

### Modified
- `resources/scripts/routers/routes/server.ts`
- `resources/scripts/components/server/modpacks/ModpacksContainer.tsx`

### API Used
- `resources/scripts/api/routes/server/startup.ts`
  - `getServerStartup()` hook

### Related Components
- `ModpackSearch.tsx` - Only rendered when supported
- `ModpackList.tsx` - Only rendered when supported
- `ModpackDetails.tsx` - Only rendered when supported

## Summary

This implementation provides a better user experience by:
1. Always showing the modpacks tab when mods are enabled
2. Checking support via API rather than hiding the tab
3. Providing clear feedback about why modpacks aren't available
4. Giving users actionable steps (contact admin)

The approach is cleaner, more maintainable, and provides better UX than completely hiding the tab.
