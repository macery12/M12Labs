# Password Reset Refactor - Implementation Plan

## Overview
Refactor password reset to conditionally use email-based reset when email notifications are enabled, or fall back to recovery code method when disabled.

## Current State
- Recovery code-based password reset exists in `ForgotPasswordController.php`
- `PasswordResetRequested` event exists but is not dispatched
- Email template exists at `resources/views/emails/auth/password-reset.blade.php`
- UI shows email + recovery code + new password fields

## Requirements

### 1. Backend Implementation

#### A. Password Reset Token System
Create a new `password_reset_tokens` table:
```sql
CREATE TABLE password_reset_tokens (
    email VARCHAR(255) NOT NULL,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NULL,
    PRIMARY KEY (email)
);
```

#### B. New API Endpoints

**Check if email reset is enabled:**
```php
GET /api/auth/password-reset/method
Response: { "method": "email" | "recovery_code" }
```

**Request email reset:**
```php
POST /api/auth/password-reset/email
Body: { "email": "user@example.com" }
Response: { "message": "If account exists, reset email sent" }
```

**Validate reset token:**
```php
POST /api/auth/password-reset/reset
Body: { "token": "abc123", "email": "...", "password": "...", "password_confirmation": "..." }
Response: { "success": true }
```

#### C. Password Reset Service
Create `app/Services/Auth/PasswordResetService.php`:
```php
class PasswordResetService {
    public function sendResetLink(string $email): bool
    public function validateToken(string $email, string $token): bool
    public function resetPassword(string $email, string $token, string $password): bool
}
```

#### D. Event Dispatch
In `PasswordResetService::sendResetLink()`:
```php
event(new PasswordResetRequested(
    user: $user,
    resetUrl: url("/auth/password/reset/{$token}?email=" . urlencode($email)),
    correlationId: Str::uuid()->toString()
));
```

### 2. Frontend Implementation

#### A. Method Detection
```tsx
const [resetMethod, setResetMethod] = useState<'email' | 'recovery_code'>('email');

useEffect(() => {
    fetch('/api/auth/password-reset/method')
        .then(r => r.json())
        .then(data => setResetMethod(data.method));
}, []);
```

#### B. Conditional UI
**Email Method:**
- Show: Email input only
- On submit: Send reset link
- Display: "Check your email for reset link"

**Recovery Code Method:**
- Show: Email + Recovery Code + New Password + Confirm Password
- On submit: Validate and reset immediately
- Display: Current behavior

#### C. Reset Token Page
Create `ResetPasswordWithTokenContainer.tsx`:
- Extract token and email from URL params
- Show: New Password + Confirm Password fields
- On submit: Call `/api/auth/password-reset/reset`

### 3. Security Considerations

#### Account Enumeration Prevention
- Always return same message whether account exists or not
- Don't reveal if email is registered
- Use consistent response times

#### Token Security
- Generate cryptographically secure random tokens (64+ characters)
- Hash tokens before storing in database
- Set expiration time (1 hour recommended)
- Single-use tokens (delete after use)

#### Rate Limiting
- Limit reset requests: 3 per hour per IP
- Limit reset requests: 5 per hour per email
- Use Laravel's rate limiter

### 4. Migration Path

#### Phase 1: Infrastructure
1. Create migration for password_reset_tokens table
2. Create PasswordResetService
3. Create API endpoints
4. Add routes

#### Phase 2: Email Flow
1. Implement token generation and email sending
2. Dispatch PasswordResetRequested event
3. Test email delivery

#### Phase 3: Frontend
1. Add method detection
2. Update ForgotPasswordContainer for conditional UI
3. Create ResetPasswordWithTokenContainer
4. Add routing for reset token page

#### Phase 4: Testing
1. Test email flow end-to-end
2. Test recovery code flow (ensure still works)
3. Test edge cases (expired tokens, invalid tokens, etc.)
4. Security testing (enumeration, rate limits)

## Email Notification Toggle

Check in backend:
```php
use Everest\Models\EmailNotificationSetting;

$emailResetEnabled = EmailNotificationSetting::isEnabled('auth.password_reset');
```

Return method based on this check:
- If enabled AND email configured: Use email method
- Otherwise: Use recovery code method

## Files to Modify

### Backend
- [ ] `database/migrations/[timestamp]_create_password_reset_tokens_table.php`
- [ ] `app/Services/Auth/PasswordResetService.php`
- [ ] `app/Http/Controllers/Auth/ForgotPasswordController.php`
- [ ] `routes/auth.php`

### Frontend  
- [ ] `resources/scripts/components/auth/ForgotPasswordContainer.tsx`
- [ ] `resources/scripts/components/auth/ResetPasswordWithTokenContainer.tsx`
- [ ] `resources/scripts/api/routes/auth/password-reset.ts`

### Configuration
- [ ] Update `email_notification_settings` seed/migration to enable password_reset by default

## Testing Checklist

- [ ] Email method: Request reset link
- [ ] Email method: Receive email with link
- [ ] Email method: Click link and reset password
- [ ] Email method: Try expired token (should fail)
- [ ] Email method: Try used token (should fail)
- [ ] Recovery code method: Still works as before
- [ ] Method detection: Returns correct method based on settings
- [ ] Rate limiting: Prevents abuse
- [ ] Account enumeration: Same response for existing/non-existing accounts
- [ ] Toggle: Changing email notification setting changes method

## Estimated Effort
- Backend: 4-6 hours
- Frontend: 3-4 hours
- Testing: 2-3 hours
- **Total: 9-13 hours**

## Notes
- Laravel has built-in password reset functionality that could be adapted
- Consider using `Illuminate\Auth\Passwords\PasswordBroker`
- Review Laravel docs: https://laravel.com/docs/10.x/passwords
