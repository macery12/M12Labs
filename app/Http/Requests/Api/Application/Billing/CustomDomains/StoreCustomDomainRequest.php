<?php

namespace Everest\Http\Requests\Api\Application\Billing\CustomDomains;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class StoreCustomDomainRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::BILLING_UPDATE;
    }

    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:191', 'regex:/^(?!-)[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/'],
            'cloudflare_zone_id' => 'nullable|string|max:191',
            'wildcard_enabled' => 'sometimes|boolean',
            'enabled' => 'sometimes|boolean',
        ];
    }
}
