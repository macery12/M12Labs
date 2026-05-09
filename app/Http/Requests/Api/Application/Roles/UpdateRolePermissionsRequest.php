<?php

namespace Everest\Http\Requests\Api\Application\Roles;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateRolePermissionsRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'permissions'   => 'nullable|array',
            'permissions.*' => 'string',
        ];
    }

    public function permission(): string
    {
        return AdminRole::ROLES_UPDATE;
    }
}
