<?php

namespace Everest\Http\Controllers\Api\Application;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Everest\Facades\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Everest\Exceptions\DisplayException;
use Everest\Models\PasswordResetRequest;
use Everest\Services\Users\UserUpdateService;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Everest\Transformers\Api\Application\PasswordResetRequestTransformer;

class PasswordResetRequestController extends ApplicationApiController
{
    public function __construct(
        private ValidationFactory $validation,
        private UserUpdateService $updateService
    ) {
        parent::__construct();
    }

    /**
     * Get all password reset requests (admin view).
     */
    public function index(Request $request): array
    {
        $perPage = (int) $request->query('per_page', 50);
        if ($perPage < 1 || $perPage > 100) {
            $perPage = 50;
        }

        $query = PasswordResetRequest::query()->with(['user', 'reviewer']);

        // Filter by status if provided
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $requests = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->fractal->collection($requests)
            ->transformWith($this->getTransformer(PasswordResetRequestTransformer::class))
            ->toArray();
    }

    /**
     * Get a specific password reset request.
     */
    public function view(Request $request, PasswordResetRequest $resetRequest): array
    {
        return $this->fractal->item($resetRequest)
            ->transformWith($this->getTransformer(PasswordResetRequestTransformer::class))
            ->toArray();
    }

    /**
     * Approve a password reset request and generate a temporary password.
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Everest\Exceptions\Model\DataValidationException
     */
    public function approve(Request $request, PasswordResetRequest $resetRequest): array
    {
        if (!$resetRequest->isPending()) {
            throw new DisplayException('This request has already been reviewed.');
        }

        $validator = $this->validation->make($request->all(), [
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $data = $validator->validate();

        // Generate a secure random password
        $temporaryPassword = $this->generateSecurePassword();

        // Update the user's password
        $this->updateService->handle($resetRequest->user, [
            'password' => $temporaryPassword,
        ]);

        // Update the reset request
        $resetRequest->update([
            'status' => PasswordResetRequest::STATUS_APPROVED,
            'generated_password' => encrypt($temporaryPassword),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'admin_notes' => $data['admin_notes'] ?? null,
        ]);

        Activity::event('admin:password-reset.approved')
            ->property('request_id', $resetRequest->id)
            ->property('target_user_id', $resetRequest->user_id)
            ->property('ip', $request->ip())
            ->property('user_agent', $request->userAgent())
            ->log();

        // Log activity on the target user as well
        Activity::event('user:password-reset.admin-approved')
            ->subject($resetRequest->user)
            ->property('request_id', $resetRequest->id)
            ->property('reviewed_by', $request->user()->id)
            ->property('ip', $request->ip())
            ->log();

        return $this->fractal->item($resetRequest->fresh())
            ->transformWith($this->getTransformer(PasswordResetRequestTransformer::class))
            ->parseIncludes(['temporary_password'])
            ->toArray();
    }

    /**
     * Deny a password reset request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function deny(Request $request, PasswordResetRequest $resetRequest): array
    {
        if (!$resetRequest->isPending()) {
            throw new DisplayException('This request has already been reviewed.');
        }

        $validator = $this->validation->make($request->all(), [
            'admin_notes' => ['required', 'string', 'max:1000'],
        ]);

        $data = $validator->validate();

        $resetRequest->update([
            'status' => PasswordResetRequest::STATUS_DENIED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'admin_notes' => $data['admin_notes'],
        ]);

        Activity::event('admin:password-reset.denied')
            ->property('request_id', $resetRequest->id)
            ->property('target_user_id', $resetRequest->user_id)
            ->property('ip', $request->ip())
            ->property('user_agent', $request->userAgent())
            ->log();

        // Log activity on the target user as well
        Activity::event('user:password-reset.admin-denied')
            ->subject($resetRequest->user)
            ->property('request_id', $resetRequest->id)
            ->property('reviewed_by', $request->user()->id)
            ->property('reason', $data['admin_notes'])
            ->property('ip', $request->ip())
            ->log();

        return $this->fractal->item($resetRequest->fresh())
            ->transformWith($this->getTransformer(PasswordResetRequestTransformer::class))
            ->toArray();
    }

    /**
     * Generate a secure random password.
     */
    private function generateSecurePassword(): string
    {
        // Generate a 16-character password with mixed case, numbers, and symbols
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $numbers = '0123456789';
        $symbols = '!@#$%^&*()_+-=[]{}|;:,.<>?';

        $password = '';
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $symbols[random_int(0, strlen($symbols) - 1)];

        // Fill the rest with random characters
        $allChars = $lowercase . $uppercase . $numbers . $symbols;
        for ($i = 0; $i < 12; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }

        // Shuffle the password
        return str_shuffle($password);
    }
}
