<?php

namespace Everest\Http\Requests\Api\Application\Auth;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class GetJGuardRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::AUTH_READ;
    }
}
