<?php

namespace Everest\Http\Requests\Api\Application\Email;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateResendSettingsRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'enabled' => 'boolean',
            'api_key' => 'nullable|string|max:255',
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
     * 
     * Only includes fields that are actually present in the request.
     * This prevents overwriting existing database values with empty strings
     * when doing partial updates (e.g., toggling enabled only).
     */
    public function normalize(?array $only = null): array
    {
        $data = [];
        
        // Enabled is always included (boolean field with default)
        $data['modules:email:resend:enabled'] = $this->input('enabled', false) ? 'true' : 'false';
        
        // Only include other fields if they exist in the request
        // This allows partial updates without overwriting existing values
        if ($this->has('api_key')) {
            $data['modules:email:resend:api_key'] = $this->input('api_key', '');
        }
        
        if ($this->has('from_email')) {
            $data['modules:email:resend:from_email'] = $this->input('from_email', '');
        }
        
        if ($this->has('from_name')) {
            $data['modules:email:resend:from_name'] = $this->input('from_name', '');
        }
        
        if ($this->has('reply_to')) {
            $data['modules:email:resend:reply_to'] = $this->input('reply_to', '');
        }
        
        return $data;
    }
}
