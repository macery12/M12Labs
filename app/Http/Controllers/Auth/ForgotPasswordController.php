<?php

namespace Everest\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;
use Everest\Exceptions\DisplayException;
use Everest\Services\Users\UserUpdateService;
use Everest\Models\User;
use Everest\Http\Requests\Auth\ForgotPasswordRequest;

class ForgotPasswordController extends AbstractLoginController
{
    /**
     * ForgotPasswordController constructor.
     */
    public function __construct(private UserUpdateService $updateService)
    {
        parent::__construct();
    }

    /**
     * Validate the information provided for resetting a password.
     */
    public function verify(ForgotPasswordRequest $request): JsonResponse|RedirectResponse
    {
        try {
            $user = User::where('email', $request->input('email'))->firstOrFail();
        } catch (\Exception $ex) {
            throw new DisplayException('The information provided was incorrect.');
        }

        // Check if user has a recovery code
        if (!$user->recovery_code) {
            throw new DisplayException('The information provided was incorrect.');
        }

        // Decrypt the recovery code and compare with provided code
        try {
            $decryptedCode = Crypt::decryptString($user->recovery_code);
        } catch (\Exception $e) {
            throw new DisplayException('The information provided was incorrect.');
        }

        if ($decryptedCode !== $request->input('code')) {
            throw new DisplayException('The information provided was incorrect.');
        }

        $user = $this->updateService->handle($user, ['password' => $request->input('password')]);

        if (!$user->use_totp) {
            return $this->sendLoginResponse($user, $request);
        }

        return redirect()->route('auth.login');
    }
}
