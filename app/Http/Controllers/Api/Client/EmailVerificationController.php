<?php

namespace Everest\Http\Controllers\Api\Client;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Everest\Services\Email\EmailSettingsReader;
use Everest\Services\Auth\EmailVerificationService;

class EmailVerificationController extends ClientApiController
{
    public function __construct(
        private EmailVerificationService $emailVerificationService,
        private EmailSettingsReader $settings,
    ) {
        parent::__construct();
    }

    /**
     * Send or resend an email verification link to the authenticated user.
     */
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return new JsonResponse(['message' => 'Email already verified.'], Response::HTTP_OK);
        }

        // Verification emails cannot be switched off on their own; this is
        // only whether the panel sends mail at all.
        if (!$this->settings->deliveryEnabled()) {
            return new JsonResponse([
                'message' => 'Email verification emails are currently disabled.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $this->emailVerificationService->send($user);

        return new JsonResponse(['message' => 'Verification email sent.'], Response::HTTP_ACCEPTED);
    }
}
