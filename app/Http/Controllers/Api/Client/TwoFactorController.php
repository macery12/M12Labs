<?php

namespace Everest\Http\Controllers\Api\Client;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Everest\Services\Users\TwoFactorSetupService;
use Everest\Services\Users\ToggleTwoFactorService;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class TwoFactorController extends ClientApiController
{
    /**
     * TwoFactorController constructor.
     */
    public function __construct(
        private ToggleTwoFactorService $toggleTwoFactorService,
        private TwoFactorSetupService $setupService,
        private ValidationFactory $validation
    ) {
        parent::__construct();
    }

    /**
     * Returns two-factor token credentials that allow a user to configure
     * it on their account. If two-factor is already enabled this endpoint
     * will return a 400 error.
     *
     * @throws \Everest\Exceptions\Model\DataValidationException
     * @throws \Everest\Exceptions\Repository\RecordNotFoundException
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->user()->use_totp) {
            throw new BadRequestHttpException('Two-factor authentication is already enabled on this account.');
        }

        $user = $request->user();
        
        // Check if user has any recovery paths
        $hasRecoveryCode = !empty($user->recovery_code);
        $hasDiscord = !empty($user->external_id) || !empty($user->discord_username);
        
        $warnings = [];
        if (!$hasRecoveryCode && !$hasDiscord) {
            $warnings[] = 'You do not have any account recovery methods set up. If you lose access to your 2FA device, you will need to request admin assistance to regain access.';
        }

        return new JsonResponse([
            'data' => $this->setupService->handle($request->user()),
            'warnings' => $warnings,
            'recovery_paths' => [
                'recovery_code' => $hasRecoveryCode,
                'discord' => $hasDiscord,
            ],
        ]);
    }

    /**
     * Updates a user's account to have two-factor enabled.
     *
     * @throws \Throwable
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $validator = $this->validation->make($request->all(), [
            'code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'string'],
        ]);

        $data = $validator->validate();
        if (!password_verify($data['password'], $request->user()->password)) {
            throw new BadRequestHttpException('The password provided was not valid.');
        }

        $tokens = $this->toggleTwoFactorService->handle($request->user(), $data['code'], true);

        Activity::event('user:two-factor.enabled')
            ->property('ip', $request->ip())
            ->property('user_agent', $request->userAgent())
            ->log();

        return new JsonResponse([
            'object' => 'recovery_tokens',
            'attributes' => [
                'tokens' => $tokens,
            ],
        ]);
    }

    /**
     * Disables two-factor authentication on an account.
     * Requires either password + 2FA code, or password + recovery code.
     *
     * @throws \Throwable
     * @throws \Illuminate\Validation\ValidationException
     */
    public function delete(Request $request): JsonResponse
    {
        $validator = $this->validation->make($request->all(), [
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'size:6'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $data = $validator->validate();

        if (!password_verify($data['password'], $request->user()->password)) {
            throw new BadRequestHttpException('The password provided was not valid.');
        }

        /** @var \Everest\Models\User $user */
        $user = $request->user();

        // Require either a valid 2FA code or recovery code to disable
        $validCode = false;

        if (!empty($data['code'])) {
            // Verify 2FA code
            try {
                $decrypted = app(\Illuminate\Contracts\Encryption\Encrypter::class)->decrypt($user->totp_secret);
                $valid = app(\PragmaRX\Google2FA\Google2FA::class)->verifyKey(
                    $decrypted,
                    $data['code'],
                    config('everest.auth.2fa.window')
                );
                if ($valid) {
                    $validCode = true;
                }
            } catch (\Exception $e) {
                // Invalid code
            }
        } elseif (!empty($data['recovery_code'])) {
            // Verify recovery code
            if ($user->recovery_code && password_verify($data['recovery_code'], $user->recovery_code)) {
                $validCode = true;
            }
        }

        if (!$validCode) {
            throw new BadRequestHttpException('You must provide either a valid 2FA code or recovery code to disable two-factor authentication.');
        }

        $user->update([
            'totp_authenticated_at' => Carbon::now(),
            'use_totp' => false,
        ]);

        // Delete all recovery tokens
        $user->recoveryTokens()->delete();

        Activity::event('user:two-factor.disabled')
            ->property('ip', $request->ip())
            ->property('user_agent', $request->userAgent())
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }
}
