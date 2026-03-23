<?php

namespace Everest\Http\Requests\Api\Application\Email;

use Everest\Http\Requests\Api\Application\ApplicationApiRequest;
use Everest\Models\AdminRole;

class ManageDeferredEmailRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::EMAIL_SEND;
    }
}
