<?php

namespace Everest\Transformers\Api\Application;

use Everest\Models\PasswordResetRequest;
use Everest\Transformers\Api\Transformer;
use League\Fractal\Resource\Item;
use League\Fractal\Resource\NullResource;

class PasswordResetRequestTransformer extends Transformer
{
    /**
     * List of resources that can be included.
     */
    protected array $availableIncludes = ['user', 'reviewer', 'temporary_password'];

    /**
     * Return the resource name for the JSONAPI output.
     */
    public function getResourceName(): string
    {
        return PasswordResetRequest::RESOURCE_NAME;
    }

    /**
     * Return a transformed PasswordResetRequest model.
     */
    public function transform(PasswordResetRequest $model): array
    {
        return [
            'id' => $model->id,
            'user_id' => $model->user_id,
            'discord_username' => $model->discord_username,
            'contact_email' => $model->contact_email,
            'reason' => $model->reason,
            'status' => $model->status,
            'reviewed_by' => $model->reviewed_by,
            'reviewed_at' => $model->reviewed_at?->toIso8601String(),
            'admin_notes' => $model->admin_notes,
            'ip_address' => $model->ip_address,
            'user_agent' => $model->user_agent,
            'created_at' => $model->created_at->toIso8601String(),
            'updated_at' => $model->updated_at->toIso8601String(),
        ];
    }

    /**
     * Include the user who made the request.
     */
    public function includeUser(PasswordResetRequest $model): Item|NullResource
    {
        if (!$this->authorize(AdminAcl::RESOURCE_USERS)) {
            return $this->null();
        }

        return $this->item($model->user, new UserTransformer());
    }

    /**
     * Include the admin who reviewed the request.
     */
    public function includeReviewer(PasswordResetRequest $model): Item|NullResource
    {
        if (!$this->authorize(AdminAcl::RESOURCE_USERS) || !$model->reviewer) {
            return $this->null();
        }

        return $this->item($model->reviewer, new UserTransformer());
    }

    /**
     * Include the temporary password (only if just approved and encrypted).
     */
    public function includeTemporaryPassword(PasswordResetRequest $model): array|NullResource
    {
        if (!$this->authorize(AdminAcl::RESOURCE_USERS) || !$model->generated_password) {
            return $this->null();
        }

        try {
            $password = decrypt($model->generated_password);
            return [
                'temporary_password' => $password,
            ];
        } catch (\Exception $e) {
            return $this->null();
        }
    }
}
