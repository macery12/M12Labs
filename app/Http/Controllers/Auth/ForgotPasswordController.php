<?php

namespace Everest\Http\Controllers\Auth;

use Everest\Models\User;
use Everest\Models\Setting;
use Everest\Models\EmailNotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Everest\Exceptions\DisplayException;
use Everest\Services\Users\UserUpdateService;
use Everest\Services\Auth\PasswordResetService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rules\Password;

class ForgotPasswordController extends AbstractLoginController
{
    /**
     * ForgotPasswordController constructor.
     */
    public function __construct(
        private UserUpdateService $updateService,
        private PasswordResetService $passwordResetService
    )
    {
        parent::__construct();
    }

    /**
     * Validate the information provided for resetting a password.
     */
    public function verify(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $user = User::where('email', $request->input('email'))->firstOrFail();
        } catch (ModelNotFoundException $ex) {
            throw new DisplayException('The information provided was incorrect.');
        }

        if (!$user->recovery_code || !password_verify($request->input('code'), $user->recovery_code)) {
            throw new DisplayException('The information provided was incorrect.');
        }

        if ($request->input('password') !== $request->input('password_confirm')) {
            throw new DisplayException('The passwords entered do not match.');
        }

        $user = $this->updateService->handle($user, ['password' => $request->input('password')]);

        if (!$user->use_totp) {
            return $this->sendLoginResponse($user, $request);
        }

        return response()->json(['redirect_to' => route('auth.login')]);
    }

    public function method(): JsonResponse
    {
        return response()->json([
            'method' => $this->isEmailResetEnabled() ? 'email' : 'recovery_code',
        ]);
    }

    public function requestEmailReset(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        if ($this->isEmailResetEnabled()) {
            $this->passwordResetService->sendResetLink($request->string('email')->toString());
        }

        return response()->json([
            'message' => 'If account exists, reset email sent',
        ]);
    }

    public function resetWithToken(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ],
        ]);

        $success = $this->passwordResetService->resetPassword(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString()
        );

        if (!$success) {
            throw new DisplayException('The password reset token is invalid or has expired.');
        }

        return response()->json(['success' => true]);
    }

    private function isEmailResetEnabled(): bool
    {
        if (!EmailNotificationSetting::isEnabled('auth.password_reset')) {
            return false;
        }

        $emailResendEnabled = strtolower((string) Setting::get('settings::modules:email:resend:enabled', '0'));
        if (in_array($emailResendEnabled, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        $mailer = config('mail.default');

        if (in_array($mailer, ['array', 'log'], true) || !config('mail.from.address')) {
            return false;
        }

        if ($mailer === 'smtp' && !config('mail.mailers.smtp.host')) {
            return false;
        }

        return (bool) config("mail.mailers.{$mailer}.transport");
    }
}
