<?php

namespace Everest\Http\Controllers\Auth;

use Everest\Models\User;
use Illuminate\Http\Request;
use Everest\Facades\Activity;
use Illuminate\Http\JsonResponse;
use Everest\Exceptions\DisplayException;
use Everest\Models\PasswordResetRequest;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;

class PublicPasswordResetRequestController extends AbstractLoginController
{
    public function __construct(private ValidationFactory $validation)
    {
        parent::__construct();
    }

    /**
     * Create a new password reset request (public endpoint for logged-out users).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $validator = $this->validation->make($request->all(), [
            'account_identifier' => ['required', 'string', 'max:191'],
            'discord_username' => ['nullable', 'string', 'max:191'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $data = $validator->validate();

        // Ensure at least one contact method is provided
        if (empty($data['discord_username']) && empty($data['contact_email'])) {
            throw new DisplayException('You must provide either a Discord username or contact email.');
        }

        // Verify the account exists (by email or username)
        $user = User::where('email', $data['account_identifier'])
            ->orWhere('username', $data['account_identifier'])
            ->first();

        if (!$user) {
            // Generic error to prevent account enumeration
            throw new DisplayException('Invalid account information provided.');
        }

        // Check if there's already a pending request for this user
        $existingPending = PasswordResetRequest::where('user_id', $user->id)
            ->where('status', PasswordResetRequest::STATUS_PENDING)
            ->exists();

        if ($existingPending) {
            throw new DisplayException('A password reset request is already pending for this account. Please wait for it to be reviewed.');
        }

        // Create the reset request
        $resetRequest = PasswordResetRequest::create([
            'user_id' => $user->id,
            'discord_username' => $data['discord_username'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'reason' => $data['reason'],
            'status' => PasswordResetRequest::STATUS_PENDING,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        Activity::event('user:password-reset.requested')
            ->subject($user)
            ->property('request_id', $resetRequest->id)
            ->property('discord_username', $data['discord_username'] ?? null)
            ->property('ip', $request->ip())
            ->property('user_agent', $request->userAgent())
            ->log();

        return new JsonResponse([
            'success' => true,
            'message' => 'Your password reset request has been submitted. An administrator will review it shortly.',
        ]);
    }
}
