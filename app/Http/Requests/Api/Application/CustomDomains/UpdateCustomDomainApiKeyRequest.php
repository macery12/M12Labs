<?php

namespace Everest\Http\Requests\Api\Application\CustomDomains;

class UpdateCustomDomainApiKeyRequest extends StoreCustomDomainApiKeyRequest
{
    public function rules(): array
    {
        $apiKey = $this->route('apiKey');
        $id = $apiKey?->id ?? 'NULL';

        return [
            'name' => 'sometimes|required|string|max:191|unique:custom_domain_api_keys,name,' . $id,
            'token' => 'nullable|string|min:20|max:500',
            'enabled' => 'sometimes|boolean',
        ];
    }
}
