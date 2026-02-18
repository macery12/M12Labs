<?php

namespace Everest\Http\Requests\Api\Application\CustomDomains;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateCustomDomainSettingsRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::BILLING_UPDATE;
    }

    public function rules(): array
    {
        return [
            'cloudflare_token' => ['sometimes', 'nullable', 'string', 'min:20', 'max:500'],
            'allow_wildcard' => ['required', 'boolean'],
            'max_wildcards_per_user' => ['required', 'integer', 'min:1', 'max:100'],
            'rate_limit_create_per_minute' => ['required', 'integer', 'min:1', 'max:1000'],
            'rate_limit_sync_per_minute' => ['required', 'integer', 'min:1', 'max:1000'],
            'rate_limit_billing_options_per_minute' => ['required', 'integer', 'min:1', 'max:2000'],
        ];
    }
}
