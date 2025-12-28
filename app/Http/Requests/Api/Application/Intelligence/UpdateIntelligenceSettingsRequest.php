<?php

namespace Everest\Http\Requests\Api\Application\Intelligence;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateIntelligenceSettingsRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'enabled' => 'nullable|bool',
            'key' => 'nullable',
            'user_access' => 'nullable|bool',
            'endpoint' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    // Only validate if value is provided (not null or empty)
                    if ($value !== null && $value !== '') {
                        // Validate it's a URL
                        if (!filter_var($value, FILTER_VALIDATE_URL)) {
                            $fail('The endpoint must be a valid URL.');
                            return;
                        }
                        
                        // Validate it starts with https://
                        if (!str_starts_with($value, 'https://')) {
                            $fail('The endpoint must use HTTPS.');
                            return;
                        }
                        
                        $parsed = parse_url($value);
                        // Ensure no @ in the authority part (prevents URL confusion attacks)
                        if (isset($parsed['user']) || strpos($value, '@') !== false) {
                            $fail('The endpoint URL contains invalid characters.');
                        }
                    }
                },
            ],
            'model' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    // Only validate if value is provided (not null or empty)
                    if ($value !== null && $value !== '') {
                        if (!is_string($value)) {
                            $fail('The model must be a string.');
                            return;
                        }
                        if (strlen($value) < 1 || strlen($value) > 100) {
                            $fail('The model must be between 1 and 100 characters.');
                        }
                    }
                },
            ],
        ];
    }

    public function permission(): string
    {
        return AdminRole::AI_UPDATE;
    }
}
