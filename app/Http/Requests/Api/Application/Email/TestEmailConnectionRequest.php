<?php

namespace Everest\Http\Requests\Api\Application\Email;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class TestEmailConnectionRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [];
    }

    public function permission(): string
    {
        return AdminRole::EMAIL_SEND;
    }
}
