<?php

namespace Everest\Http\Requests\Api\Application\Email;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateResendSettingsRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'enabled' => 'nullable|bool',
            'api_key' => 'nullable|string|min:1|max:255',
            'from_email' => 'nullable|email|max:255',
            'from_name' => 'nullable|string|max:255',
            'reply_to' => 'nullable|email|max:255',
        ];
    }

    public function permission(): string
    {
        return AdminRole::EMAIL_UPDATE;
    }

    /**
     * Normalize the request data for storage.
     */
    public function normalize(): array
    {
        return [
            'modules:email:resend:enabled' => $this->input('enabled', false) ? 'true' : 'false',
            'modules:email:resend:api_key' => $this->input('api_key', ''),
            'modules:email:resend:from_email' => $this->input('from_email', ''),
            'modules:email:resend:from_name' => $this->input('from_name', ''),
            'modules:email:resend:reply_to' => $this->input('reply_to', ''),
        ];
    }
}
