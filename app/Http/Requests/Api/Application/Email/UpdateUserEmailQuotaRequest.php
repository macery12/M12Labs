<?php

namespace Everest\Http\Requests\Api\Application\Email;

use Everest\Http\Requests\Api\Application\ApplicationApiRequest;
use Everest\Models\AdminRole;

class UpdateUserEmailQuotaRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'plan' => 'nullable|in:free,pro,scale',
        ];
    }

    public function permission(): string
    {
        return AdminRole::EMAIL_UPDATE;
    }
}
