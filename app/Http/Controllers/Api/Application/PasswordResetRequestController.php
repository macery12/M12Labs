<?php

namespace Everest\Http\Controllers\Api\Application;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Everest\Facades\Activity;
use Illuminate\Http\JsonResponse;
use Everest\Exceptions\DisplayException;
use Everest\Models\PasswordResetRequest;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Everest\Transformers\Api\Application\PasswordResetRequestTransformer;

class PasswordResetRequestController extends ApplicationApiController
{
    public function __construct(
        private ValidationFactory $validation
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
            ->transformWith(PasswordResetRequestTransformer::class)
            ->toArray();
    }

    /**
     * Get count of pending password reset requests.
     */
    public function count(Request $request): JsonResponse
    {
        $count = PasswordResetRequest::where('status', PasswordResetRequest::STATUS_PENDING)->count();

        return new JsonResponse([
            'count' => $count,
        ]);
    }

    /**
     * Get a specific password reset request.
     */
    public function view(Request $request, PasswordResetRequest $resetRequest): array
    {
        return $this->fractal->item($resetRequest)
            ->transformWith(PasswordResetRequestTransformer::class)
            ->toArray();
    }

    /**
     * Approve a password reset request.
     * Note: Admin must manually reset the password via the Users page.
     *
     * @throws \Illuminate\Validation\ValidationException
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

        // Update the reset request (no password generation - admin handles manually)
        $resetRequest->update([
            'status' => PasswordResetRequest::STATUS_APPROVED,
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
            ->transformWith(PasswordResetRequestTransformer::class)
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
            ->transformWith(PasswordResetRequestTransformer::class)
            ->toArray();
    }
}
