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
                'url',
                'starts_with:https://',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $parsed = parse_url($value);
                        // Ensure no @ in the authority part (prevents URL confusion attacks)
                        if (isset($parsed['user']) || strpos($value, '@') !== false) {
                            $fail('The endpoint URL contains invalid characters.');
                        }
                    }
                },
            ],
            'model' => 'nullable|string|min:1|max:100',
        ];
    }

    public function permission(): string
    {
        return AdminRole::AI_UPDATE;
    }
}
