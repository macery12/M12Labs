<?php

namespace Everest\Http\Requests\Api\Application\Email;

use Everest\Http\Requests\Api\Application\ApplicationApiRequest;
use Everest\Models\AdminRole;

class UpdateEmailNotificationSettingRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'enabled' => 'required|boolean',
        ];
    }

    public function permission(): string
    {
        return AdminRole::EMAIL_UPDATE;
    }
}
