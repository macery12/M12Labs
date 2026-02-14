<?php

namespace Everest\Http\Controllers\Api\Client;

use Illuminate\Http\Request;
use Everest\Facades\Activity;
use Illuminate\Http\JsonResponse;
use Everest\Exceptions\DisplayException;
use Everest\Models\PasswordResetRequest;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;

class PasswordResetRequestController extends ClientApiController
{
    public function __construct(private ValidationFactory $validation)
    {
        parent::__construct();
    }

    /**
     * Get all password reset requests for the current user.
     */
    public function index(Request $request): JsonResponse
    {
        $requests = $request->user()
            ->passwordResetRequests()
            ->orderBy('created_at', 'desc')
            ->get();

        return new JsonResponse([
            'data' => $requests,
        ]);
    }

    /**
     * Create a new password reset request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        $validator = $this->validation->make($request->all(), [
            'discord_username' => ['nullable', 'string', 'max:191'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $data = $validator->validate();

        // Ensure at least one contact method is provided
        if (empty($data['discord_username']) && empty($data['contact_email'])) {
            throw new DisplayException('You must provide either a Discord username or contact email.');
        }

        // Check if there's already a pending request
        $existingPending = $request->user()
            ->passwordResetRequests()
            ->where('status', PasswordResetRequest::STATUS_PENDING)
            ->exists();

        if ($existingPending) {
            throw new DisplayException('You already have a pending password reset request. Please wait for it to be reviewed.');
        }

        $resetRequest = PasswordResetRequest::create([
            'user_id' => $request->user()->id,
            'discord_username' => $data['discord_username'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'reason' => $data['reason'],
            'status' => PasswordResetRequest::STATUS_PENDING,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        Activity::event('user:password-reset.requested')
            ->property('request_id', $resetRequest->id)
            ->property('discord_username', $data['discord_username'] ?? null)
            ->property('ip', $request->ip())
            ->property('user_agent', $request->userAgent())
            ->log();

        return new JsonResponse([
            'data' => $resetRequest,
        ]);
    }
}
