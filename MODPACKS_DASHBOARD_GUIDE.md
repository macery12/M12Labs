# Modpacks Dashboard Implementation Guide

## Overview

This implementation moves the modpacks browsing functionality from the server-level pages to the account dashboard, allowing users to browse CurseForge modpacks and install them to any of their servers with proper environment variable configuration and server reinstallation.

## Architecture

### Frontend Structure

```
resources/scripts/
├── api/routes/account/
│   └── modpacks.ts                 # Account-level API client
├── components/account/
│   ├── ModpacksAccountContainer.tsx # Main container
│   └── modpacks/
│       ├── ModpackSearch.tsx       # Search/filter interface
│       ├── ModpackList.tsx         # Grid display with pagination
│       └── ModpackInstallModal.tsx # Server + version selection
└── routers/routes/
    └── account.ts                  # Route configuration
```

### Backend Structure

```
app/Http/Controllers/Api/Client/
└── AccountModpacksController.php  # Controller with 7 endpoints

routes/
└── api-client.php                 # API route definitions
```

## API Endpoints

### GET `/api/client/account/modpacks/search`
Search for modpacks on CurseForge.

**Query Parameters:**
- `searchFilter` (string, optional) - Search term
- `sortField` (string, optional) - Sort field (1-6)
- `sortOrder` (string, optional) - Sort order (asc/desc)
- `gameVersion` (string, optional) - Minecraft version
- `modLoaderType` (number, optional) - Mod loader ID
- `pageSize` (number, optional) - Results per page (default: 20)
- `index` (number, optional) - Page index (default: 0)

**Rate Limit:** 30 requests per minute

### GET `/api/client/account/modpacks/{modpackId}`
Get details about a specific modpack.

**Rate Limit:** 30 requests per minute

### GET `/api/client/account/modpacks/{modpackId}/files`
Get available files/versions for a modpack.

**Query Parameters:**
- `gameVersion` (string, optional)
- `modLoaderType` (number, optional)
- `pageSize` (number, optional)
- `index` (number, optional)

**Rate Limit:** 30 requests per minute

### GET `/api/client/account/modpacks/minecraft/versions`
Get available Minecraft versions.

**Rate Limit:** 10 requests per minute

### GET `/api/client/account/modpacks/minecraft/loaders`
Get available mod loader types.

**Rate Limit:** 10 requests per minute

### GET `/api/client/account/modpacks/server/{serverId}/info`
Get current modpack information for a server.

**Response:**
```json
{
  "projectId": "123456",
  "versionId": "789012",
  "modpackName": "All the Mods 9"
}
```

**Rate Limit:** 30 requests per minute

### POST `/api/client/account/modpacks/install`
Install a modpack to a server.

**Request Body:**
```json
{
  "serverId": "uuid-of-server",
  "modpackId": 123456,
  "fileId": 789012  // optional, defaults to latest
}
```

**Process:**
1. Validates server ownership and mods enabled
2. Checks for required environment variables (PROJECT_ID, API_KEY)
3. Updates environment variables:
   - `PROJECT_ID` = modpack ID
   - `VERSION_ID` = file ID (or empty for latest)
   - `API_KEY` = CurseForge API key from config
4. Syncs environment to Wings
5. Triggers server reinstall

**Rate Limit:** 5 requests per minute

## Required Server Configuration

### Egg Requirements

The target server must have a Curseforge_generic egg (or compatible) with these environment variables:

1. **PROJECT_ID** (required)
   - Variable name: `PROJECT_ID`
   - Description: The modpack project ID from CurseForge
   - Validation: Usually numeric
   - User editable: Yes

2. **VERSION_ID** (optional)
   - Variable name: `VERSION_ID`
   - Description: The file ID for a specific version
   - Validation: Numeric or empty
   - User editable: Yes
   - Note: If empty, installer downloads latest version

3. **API_KEY** (required)
   - Variable name: `API_KEY`
   - Description: CurseForge API key
   - Validation: String
   - User editable: Usually no (filled automatically)
   - Note: Populated from `config('everest.mods.curseforge_api_key')`

### Server Requirements

- Server must have `mods_enabled = true` flag set
- Server must be owned by the requesting user
- Server must use an egg with the required environment variables

## User Interface Flow

### 1. Access Modpacks Page
- Navigate to dashboard
- Click "Modpacks" in sidebar (appears when mods module enabled)
- Shows if `everest.mods.enabled` is true

### 2. Browse Modpacks
- Search by name/description
- Filter by Minecraft version
- Filter by mod loader (Forge, Fabric, Quilt, NeoForge)
- Sort by popularity, featured, last updated, etc.
- Navigate through pages

### 3. Select Modpack
- Click on modpack card
- Modal opens showing:
  - Modpack details (logo, description, stats)
  - Server selection (filtered to mods-enabled servers)
  - Current modpack on each server (if any)
  - Version selection

### 4. Select Server
- Choose from list of mods-enabled servers
- Servers show current modpack if installed
- Visual indicator if server already has this modpack

### 5. Select Version
- List shows all available files
- Each file shows:
  - Display name
  - Release type (Release/Beta/Alpha) with color coding
  - Minecraft versions supported
  - File date
  - File size
- Select specific version or latest will be used

### 6. Confirm Installation
- Warning modal appears with:
  - Data overwrite warning
  - Environment variable update info
  - Server reinstall notification
  - Selected server and version confirmation
- User must explicitly confirm

### 7. Installation Process
- Environment variables updated
- Server reinstall triggered
- Success message shown
- User can monitor on server console page

## Component Details

### ModpacksAccountContainer.tsx
Main container component that:
- Manages search state
- Loads and displays modpacks
- Handles pagination
- Shows modpack install modal
- Displays error states

### ModpackSearch.tsx
Search interface with:
- Text search input
- Minecraft version dropdown
- Mod loader dropdown
- Sort field dropdown
- Submit and clear buttons
- Loads versions/loaders from API

### ModpackList.tsx
Grid display component:
- Responsive grid (1-4 columns based on screen size)
- Modpack cards with logo, name, author, stats
- Pagination controls
- Loading states
- Empty states

### ModpackInstallModal.tsx
Complex modal component featuring:
- Modpack header with logo and stats
- Server selection list
  - Filters to mods-enabled servers
  - Shows current modpack on each server
  - Visual selection state
- Version selection list
  - File details with release type
  - Visual selection state
- Confirmation dialog
  - Warning about data overwrite
  - Installation details
  - Explicit confirmation required
- Installation progress handling
- Error handling

## Backend Controller Logic

### AccountModpacksController.php

#### Search, Get, GetFiles Methods
These methods proxy requests to the existing `CurseForgeService`:
- No server context required
- Available to all authenticated users
- Rate limited appropriately

#### GetServerModpackInfo Method
Retrieves current modpack configuration:
1. Validates server ownership
2. Queries server's environment variables
3. Extracts PROJECT_ID and VERSION_ID
4. Optionally fetches modpack name from CurseForge
5. Returns current configuration

#### Install Method
Complex installation process:
1. **Validation**
   - Verify server ownership
   - Check mods enabled on server
   - Validate CurseForge API key exists
   - Verify required environment variables in egg

2. **Configuration Update**
   - Get CurseForge API key from config
   - Set PROJECT_ID to modpack ID
   - Set VERSION_ID to file ID (or empty for latest)
   - Set API_KEY to CurseForge key
   - Preserve all other environment variables

3. **Database Update**
   - Update server_variables table with new values
   - Save all modified variables

4. **Wings Synchronization**
   - Call daemon API to sync environment
   - Ensures Wings has latest configuration

5. **Reinstall Trigger**
   - Call daemon API to trigger reinstall
   - Server will run installation script
   - Script uses env vars to download modpack

6. **Logging**
   - Log installation initiation
   - Include user, server, modpack details
   - Log any errors with stack traces

## Error Handling

### Frontend Errors
- API errors shown via flash messages
- Network errors caught and displayed
- Loading states prevent duplicate requests
- Validation prevents invalid selections

### Backend Errors
- Invalid server access → 404
- Mods not enabled → 403
- Missing API key → 500 with message
- Missing env variables → 400 with message
- Installation failures → 500 with logged details

## Security Considerations

1. **Server Ownership**
   - All operations validate user owns the server
   - SQL queries filter by owner_id

2. **Rate Limiting**
   - Search: 30 requests/minute
   - Install: 5 requests/minute
   - Prevents API abuse

3. **API Key Protection**
   - CurseForge API key stored in config
   - Not exposed to frontend
   - Automatically injected during installation

4. **Input Validation**
   - Server ID validated as UUID
   - Modpack ID validated as integer
   - File ID validated as integer

## Configuration

### Required Settings

**Mods Module Enabled:**
```php
// config/everest.php or database
'mods' => [
    'enabled' => true,
    'curseforge_api_key' => 'your-api-key-here',
]
```

**Server Mods Enabled:**
```sql
UPDATE servers SET mods_enabled = 1 WHERE id = ?;
```

**Egg Variables:**
The Curseforge_generic egg must define:
- PROJECT_ID (env_variable = 'PROJECT_ID')
- VERSION_ID (env_variable = 'VERSION_ID', optional)
- API_KEY (env_variable = 'API_KEY')

## Testing

### Manual Testing Steps

1. **Page Access**
   - Enable mods module in admin
   - Navigate to dashboard
   - Verify "Modpacks" appears in sidebar
   - Click to access page

2. **Search Functionality**
   - Enter search term
   - Select filters
   - Verify results update
   - Test pagination

3. **Modpack Details**
   - Click modpack card
   - Verify modal opens
   - Check details display correctly

4. **Server Selection**
   - Verify only mods-enabled servers shown
   - Check current modpack displays
   - Test server selection

5. **Version Selection**
   - Verify files list
   - Check version details
   - Test version selection

6. **Installation**
   - Select server and version
   - Confirm warning dialog
   - Verify installation initiates
   - Check server console for reinstall
   - Monitor installation progress

### API Testing

Use tools like Postman or curl:

```bash
# Search modpacks
curl -X GET "http://panel.example.com/api/client/account/modpacks/search?searchFilter=allthemods" \
  -H "Authorization: Bearer YOUR_API_KEY"

# Get modpack
curl -X GET "http://panel.example.com/api/client/account/modpacks/123456" \
  -H "Authorization: Bearer YOUR_API_KEY"

# Install modpack
curl -X POST "http://panel.example.com/api/client/account/modpacks/install" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"serverId":"uuid-here","modpackId":123456,"fileId":789012}'
```

## Troubleshooting

### "Mods module is not enabled"
- Check Everest settings: `everest.mods.enabled` must be true
- Verify in admin panel under settings

### "No servers available"
- Ensure at least one server has `mods_enabled = true`
- Check server admin panel, enable mods toggle

### "Server does not support modpack installation"
- Verify server egg has PROJECT_ID and API_KEY variables
- Check egg configuration in admin panel
- May need to change server egg to Curseforge_generic

### Installation fails
- Check server logs for errors
- Verify CurseForge API key is valid
- Ensure Wings daemon is running
- Check network connectivity from Wings to CurseForge

### Environment variables not updating
- Check database: `server_variables` table
- Verify Wings sync completed
- Try manual server restart

## Future Enhancements

Potential improvements for future iterations:

1. **Settings Page**
   - Configure which egg ID is the Curseforge egg
   - Set default mod loader preferences
   - Configure auto-update settings

2. **Installation Progress**
   - Real-time progress tracking
   - Download percentage
   - Installation steps feedback

3. **Modpack Management**
   - Update to newer version
   - Uninstall modpack
   - Rollback to previous version

4. **Server Auto-Rename**
   - Option to rename server to match modpack
   - Preserve original name option

5. **Batch Operations**
   - Install to multiple servers
   - Bulk update modpacks

6. **Notifications**
   - Email when installation completes
   - Alert when modpack has update available

## Support

For issues or questions:
1. Check server logs in panel
2. Check Wings daemon logs
3. Verify environment variables in startup settings
4. Check CurseForge API status
5. Review browser console for frontend errors

## License

This implementation follows the same license as Jexactyl (MIT License).
