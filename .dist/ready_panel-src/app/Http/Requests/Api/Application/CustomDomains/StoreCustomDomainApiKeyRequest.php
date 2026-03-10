<?php

namespace Everest\Http\Requests\Api\Application\CustomDomains;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class StoreCustomDomainApiKeyRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::BILLING_UPDATE;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:191|unique:custom_domain_api_keys,name',
            'token' => 'required|string|min:20|max:500',
            'enabled' => 'sometimes|boolean',
        ];
    }
}
