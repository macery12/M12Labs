<?php

namespace Everest\Http\Requests\Api\Application\Email;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class SendCustomEmailRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'to' => 'required|email',
            'subject' => 'required|string|min:1|max:255',
            'html' => 'required|string|min:1',
            'text' => 'nullable|string',
        ];
    }

    public function permission(): string
    {
        return AdminRole::EMAIL_SEND;
    }
}
