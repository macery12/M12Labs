# Discord SSO Quick Reference

## For Users

### New Discord SSO Registration
1. Click "Use Discord SSO" on the login page
2. Authorize the application on Discord
3. You'll be redirected to a registration form showing:
   - Your Discord email
   - Your Discord ID
   - A pre-filled username (from Discord, editable)
4. Set a password (required for SFTP access to your servers)
5. Click "Complete Registration"
6. You're logged in!

### Existing Discord Users
If you've already linked Discord to your account, clicking "Use Discord SSO" will log you in directly.

### Existing Email Users
If your email is already registered but Discord isn't linked, you'll see an error message. Login with your password first, then link Discord from your account settings (future feature).

## For Developers

### API Endpoints

#### POST `/auth/modules/discord`
Initiates Discord OAuth flow by returning the Discord authorization URL.

**Response**: String (Discord OAuth URL)

#### GET `/auth/modules/discord/authenticate`
Discord OAuth callback endpoint. Handles the OAuth response and stores data in session.

**Redirects to**:
- `/` - If existing user with Discord linked
- `/auth/login` - If email already exists (with error message)
- `/auth/discord/register` - If new user

#### GET `/auth/modules/discord/registration-data`
Returns Discord data stored in session for the registration form.

**Response**:
```json
{
  "discord_username": "string",
  "discord_email": "string",
  "discord_id": "string"
}
```

**Error (404)**:
```json
{
  "error": "No Discord registration data found"
}
```

#### POST `/auth/modules/discord/complete`
Completes Discord registration with user-provided details.

**Request Body**:
```json
{
  "username": "string",
  "password": "string",
  "confirm_password": "string"
}
```

**Validation**:
- `username`: Required
- `password`: Required (needed for SFTP access)
- `confirm_password`: Required, must match `password`

**Response**: Standard login response with user object and session token

### Frontend Components

#### `DiscordRegistrationContainer`
**Location**: `resources/scripts/components/auth/DiscordRegistrationContainer.tsx`

**Props**: None (uses session data)

**State**:
- `discordData`: Discord information from session
- `loading`: Loading state while fetching data

**Fields**:
- Username (text input, required)
- Password (password input, required)
- Confirm Password (password input, required)

### Session Data Structure

```php
// Discord registration data (stored during OAuth callback)
$request->session()->put('discord_registration_data', [
    'discord_id' => string,        // Discord user ID
    'discord_username' => string,  // Discord username
    'discord_email' => string,     // Discord email
    'discord_avatar' => ?string,   // Discord avatar hash (optional)
]);
```

Session data is automatically cleared after successful registration or can expire based on Laravel session configuration.

### Database

**User Model**: Uses existing `external_id` field to store Discord ID

```php
// Check for existing Discord user
$user = User::where('external_id', $discordId)->first();

// Create new user with Discord
User::create([
    'username' => $username,
    'email' => $email,
    'external_id' => $discordId,  // Discord ID stored here
    'password' => $password,       // Required for SFTP access
]);
```

### Common Issues & Solutions

#### Issue: "Discord registration data not found"
**Cause**: Session expired or user navigated to `/auth/discord/register` directly
**Solution**: User needs to restart Discord SSO flow from login page

#### Issue: "An account with this email already exists"
**Cause**: User's Discord email matches existing account but Discord isn't linked
**Solution**: User should login with password first (account linking feature coming in future update)

#### Issue: 302 redirect errors
**Cause**: Session middleware not configured or CSRF token issues
**Solution**: Ensure Laravel session middleware is active and CSRF tokens are being sent

### Testing Commands

```bash
# Build frontend
npm run build

# Check PHP syntax
php -l app/Http/Controllers/Auth/Modules/DiscordLoginController.php

# Run tests (when available)
php artisan test --filter Discord
```

### Environment Configuration

```env
# Required for Discord SSO
DISCORD_ENABLED=true
DISCORD_CLIENT_ID=your_discord_client_id
DISCORD_CLIENT_SECRET=your_discord_client_secret

# Laravel session configuration (important for Discord flow)
SESSION_DRIVER=file  # or database, redis, etc.
SESSION_LIFETIME=120
```

### Security Notes

1. **External ID Uniqueness**: Discord IDs are unique in the database
2. **No Auto-Linking**: Prevents security issues by not automatically linking Discord to existing emails
3. **Session-based**: Discord data stored in secure Laravel sessions
4. **Required Passwords**: All users must set a password for SFTP access to servers
5. **Dual Authentication**: Users can log in via Discord SSO or username/password
6. **CSRF Protection**: All endpoints protected by Laravel's CSRF middleware

### Extending the Flow

To add more OAuth providers (Google, GitHub, etc.):
1. Create similar controller extending `AbstractLoginController`
2. Add session storage logic
3. Create corresponding registration component
4. Add routes to `routes/auth.php`
5. Follow the same pattern as Discord implementation
