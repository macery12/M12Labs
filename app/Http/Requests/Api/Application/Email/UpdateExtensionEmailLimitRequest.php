<?php

namespace Everest\Http\Requests\Api\Application\Email;

use Everest\Models\AdminRole;
use Everest\Services\Email\ExtensionMailLimiter;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateExtensionEmailLimitRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'hourly_limit' => 'required|integer|min:1|max:' . ExtensionMailLimiter::MAX_PER_HOUR,
        ];
    }

    public function permission(): string
    {
        return AdminRole::EMAIL_UPDATE;
    }
}
