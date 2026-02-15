<?php

namespace Everest\Http\Requests\Api\Application\Email;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateResendSettingsRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        $rules = [
            'enabled' => 'nullable|bool',
            'api_key' => 'nullable|string|min:1|max:255',
            'from_email' => 'nullable|email|max:255',
            'from_name' => 'nullable|string|max:255',
            'reply_to' => 'nullable|email|max:255',
        ];

        // If enabling the email system, require from_email and api_key
        if ($this->input('enabled') === true) {
            $rules['from_email'] = 'required|email|max:255';
            
            // Only require API key if it's not already set
            $existingApiKey = \Everest\Models\Setting::get('settings::modules:email:resend:api_key');
            if (empty($existingApiKey) || !empty($this->input('api_key'))) {
                $rules['api_key'] = 'required|string|min:1|max:255';
            }
        }

        return $rules;
    }
    
    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'from_email.required' => 'From email address is required when email system is enabled. This domain must be verified in your Resend account.',
            'from_email.email' => 'From email address must be a valid email address.',
            'api_key.required' => 'API key is required when email system is enabled.',
        ];
    }

    public function permission(): string
    {
        return AdminRole::EMAIL_UPDATE;
    }

    /**
     * Normalize the request data for storage.
     */
    public function normalize(?array $only = null): array
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
