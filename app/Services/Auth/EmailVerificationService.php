<?php

namespace Everest\Services\Auth;

use Everest\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use Everest\Events\Email\EmailVerificationRequested;
use Everest\Models\Setting;

class EmailVerificationService
{
    private int $expiryMinutes = 60;

    public function send(User $user): void
    {
        if (!$this->emailSendingEnabled()) {
            return;
        }

        if ($user->hasVerifiedEmail()) {
            return;
        }

        $verificationUrl = URL::temporarySignedRoute(
            name: 'auth.verification.verify',
            expiration: now()->addMinutes($this->expiryMinutes),
            parameters: [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ],
        );

        event(new EmailVerificationRequested(
            user: $user,
            verificationUrl: $verificationUrl,
            correlationId: Str::uuid()->toString(),
        ));
    }

    private function emailSendingEnabled(): bool
    {
        $value = strtolower((string) Setting::get('settings::modules:email:resend:enabled', '0'));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
