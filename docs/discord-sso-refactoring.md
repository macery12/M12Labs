# Discord SSO Refactoring Summary

## Overview

This refactoring improves the Discord SSO (Single Sign-On) authentication flow to give users more control over their account creation process. Previously, Discord SSO would automatically create accounts with limited customization, which could lead to poor user experience.

## Changes Made

### 1. Session-Based Registration Flow

**Before:**
- User clicks "Discord SSO" → Discord OAuth → Account auto-created with random username → User redirected to `/account/setup`

**After:**
- User clicks "Discord SSO" → Discord OAuth → Discord data stored in session → User redirected to custom registration form → User customizes account → Account created

### 2. Key Features

#### User Customization
- **Username Selection**: Users can now choose their own username during Discord SSO registration instead of getting a random generated one
- **Optional Password**: Users can choose to:
  - Set a traditional password (allows login via both Discord SSO and username/password)
  - Use Discord-only authentication (more secure, no password required)
- **Discord Info Display**: Shows Discord email and ID during registration for transparency

#### Better Authentication Flow
- **Existing User Login**: Users with Discord already linked (via `external_id`) are automatically logged in
- **Email Conflict Handling**: Users with existing accounts but unlinked Discord receive a clear error message directing them to login first
- **Error Handling**: Improved error messages for Discord OAuth failures

### 3. Technical Implementation

#### Backend Changes (`app/Http/Controllers/Auth/Modules/DiscordLoginController.php`)

**New Endpoints:**
1. `GET /auth/modules/discord/registration-data` - Retrieves Discord data from session for the registration form
2. `POST /auth/modules/discord/complete` - Completes Discord registration with user-provided details

**Updated Logic:**
- `authenticate()` method now:
  1. Checks for existing user by Discord ID (`external_id`)
  2. If found, logs them in directly
  3. If not found, checks for existing email
  4. If email exists, shows error (prevents auto-linking for security)
  5. If new user, stores Discord data in session and redirects to registration

**Session Data Structure:**
```php
$request->session()->put('discord_registration_data', [
    'discord_id' => $account->id,
    'discord_username' => $account->username,
    'discord_email' => $account->email,
    'discord_avatar' => $account->avatar ?? null,
]);
```

#### Frontend Changes

**New Component:** `resources/scripts/components/auth/DiscordRegistrationContainer.tsx`
- Fetches Discord data from session on mount
- Displays Discord information (email, ID)
- Form with:
  - Username field (pre-filled with Discord username)
  - Checkbox for "Discord-only login"
  - Conditional password fields (shown only if checkbox is unchecked)
  - Form validation using Yup

**New API Module:** `resources/scripts/api/routes/auth/discord.ts`
- `getDiscordRegistrationData()` - Fetches session data
- `completeDiscordRegistration()` - Submits registration form

**Routing Update:** `resources/scripts/routers/AuthenticationRouter.tsx`
- Added route: `/auth/discord/register` → `DiscordRegistrationContainer`

### 4. User Flow Diagram

```
┌─────────────────┐
│  User clicks    │
│ "Discord SSO"   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Discord OAuth   │
│  Authorization  │
└────────┬────────┘
         │
         ▼
┌─────────────────────────────────┐
│ Check: User exists with         │
│ Discord ID (external_id)?       │
└────────┬────────────────────────┘
         │
    ┌────┴────┐
    │         │
   YES       NO
    │         │
    │         ▼
    │    ┌─────────────────────────┐
    │    │ Check: Email exists?    │
    │    └────────┬────────────────┘
    │             │
    │        ┌────┴────┐
    │        │         │
    │       YES       NO
    │        │         │
    │        │         ▼
    │        │    ┌────────────────────────┐
    │        │    │ Store Discord data in  │
    │        │    │ session                │
    │        │    └────────┬───────────────┘
    │        │             │
    │        │             ▼
    │        │    ┌────────────────────────┐
    │        │    │ Redirect to            │
    │        │    │ /auth/discord/register │
    │        │    └────────┬───────────────┘
    │        │             │
    │        │             ▼
    │        │    ┌────────────────────────┐
    │        │    │ User fills form:       │
    │        │    │ - Username             │
    │        │    │ - Password (optional)  │
    │        │    └────────┬───────────────┘
    │        │             │
    │        │             ▼
    │        │    ┌────────────────────────┐
    │        │    │ Create account with:   │
    │        │    │ - username             │
    │        │    │ - email (from Discord) │
    │        │    │ - external_id (Discord)│
    │        │    │ - password (optional)  │
    │        │    └────────┬───────────────┘
    │        │             │
    │        ▼             │
    │   ┌────────────┐    │
    │   │  Show error│    │
    │   │  message   │    │
    │   └────────────┘    │
    │                     │
    ▼                     ▼
┌─────────────────────────────────┐
│ Log user in and redirect to "/"│
└─────────────────────────────────┘
```

## Security Considerations

1. **Session-based storage**: Discord data is stored in the user's session, preventing unauthorized account creation
2. **No auto-linking**: Existing accounts are NOT automatically linked to Discord for security reasons
3. **Optional passwords**: Users can choose Discord-only auth for enhanced security (no password to compromise)
4. **External ID validation**: Discord IDs stored in `external_id` field are validated as unique

## Database Schema

No migrations required! The existing `users.external_id` field is used to store Discord IDs.

```sql
-- The external_id field already exists and is used to store Discord user IDs
-- From migration: 2017_06_10_152951_add_external_id_to_users.php
```

## Configuration

Discord SSO configuration remains unchanged in `.env`:

```env
DISCORD_ENABLED=true
DISCORD_CLIENT_ID=your_client_id
DISCORD_CLIENT_SECRET=your_client_secret
```

## Testing Checklist

- [ ] New user registration via Discord SSO
- [ ] Existing user login (with Discord ID already linked)
- [ ] Existing user with email but no Discord link (should show error)
- [ ] Discord OAuth failures (invalid credentials, network errors)
- [ ] Password-based registration (user sets password)
- [ ] Discord-only registration (no password)
- [ ] Form validation (username required, passwords must match)
- [ ] Session expiration (Discord data should clear after timeout)

## Breaking Changes

### Potential Impact
- Users who previously had accounts auto-created via Discord SSO will need to complete the registration flow
- The `/account/setup` redirect is removed from the Discord flow

### Migration Path
Existing users with Discord-linked accounts (those with `external_id` set) will continue to work seamlessly as they'll be auto-logged in.

## Future Enhancements

1. **Account Linking**: Allow existing users to link their Discord account from account settings
2. **Discord Avatar**: Display and optionally use Discord avatar
3. **Discord Roles**: Sync Discord server roles for permissions
4. **Multiple OAuth Providers**: Support linking multiple OAuth providers to one account

## Files Changed

```
app/Http/Controllers/Auth/Modules/DiscordLoginController.php  (130 lines modified)
resources/scripts/api/routes/auth/discord.ts                  (38 lines added)
resources/scripts/components/auth/DiscordRegistrationContainer.tsx (199 lines added)
resources/scripts/routers/AuthenticationRouter.tsx            (2 lines added)
routes/auth.php                                               (5 lines modified)
```

## Rollback Plan

If issues arise, the previous behavior can be restored by:
1. Reverting the changes to `DiscordLoginController.php`
2. Removing the new routes from `auth.php`
3. Removing the new frontend components

The database schema remains unchanged, so no data migration is needed.
