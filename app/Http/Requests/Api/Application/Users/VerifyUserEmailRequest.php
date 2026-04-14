<?php

namespace Everest\Http\Requests\Api\Application\Users;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class VerifyUserEmailRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::USERS_UPDATE;
    }

    public function rules(): array
    {
        return [
            'verified' => 'required|boolean',
        ];
    }
}
