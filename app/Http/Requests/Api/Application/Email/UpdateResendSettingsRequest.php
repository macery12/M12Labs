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

        // If enabling the email system, ensure from_email and api_key are configured
        // Either they must be in this request, OR already saved in database
        if ($this->input('enabled') === true) {
            // Check if from_email is in request or already saved
            $existingFromEmail = \Everest\Models\Setting::get('settings::modules:email:resend:from_email');
            $hasFromEmailInRequest = !empty($this->input('from_email'));
            $hasFromEmailInDatabase = !empty($existingFromEmail);
            
            // Require from_email if not in request AND not in database
            if (!$hasFromEmailInRequest && !$hasFromEmailInDatabase) {
                $rules['from_email'] = 'required|email|max:255';
            }
            
            // Check if API key is in request or already saved
            $existingApiKey = \Everest\Models\Setting::get('settings::modules:email:resend:api_key');
            $hasApiKeyInRequest = !empty($this->input('api_key'));
            $hasApiKeyInDatabase = !empty($existingApiKey);
            
            // Require API key if not in request AND not in database
            if (!$hasApiKeyInRequest && !$hasApiKeyInDatabase) {
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
            'from_email.required' => 'From email address must be configured before enabling the email system. Please set it in the "From Email" field and save settings first. The domain must be verified in your Resend account.',
            'from_email.email' => 'From email address must be a valid email address.',
            'api_key.required' => 'API key must be configured before enabling the email system. Please enter your Resend API key first.',
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
