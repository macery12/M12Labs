<?php

namespace Everest\Http\Requests\Api\Application\Billing\CustomDomains;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class StoreCustomDomainRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::CUSTOM_DOMAINS_CREATE;
    }

    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:191', 'regex:/^(?!-)[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/'],
            'cloudflare_zone_id' => 'nullable|string|max:191',
            'api_key_id' => 'nullable|integer|exists:custom_domain_api_keys,id',
            'allowed_nest_ids' => 'nullable|array',
            'allowed_nest_ids.*' => 'integer|exists:nests,id',
            'allowed_egg_ids' => 'nullable|array',
            'allowed_egg_ids.*' => 'integer|exists:eggs,id',
            'service_tag' => ['nullable', 'string', 'max:100', 'regex:/^(_?[a-z0-9][a-z0-9-]*|_[a-z0-9][a-z0-9-]*\._(?:tcp|udp)?|_[a-z0-9][a-z0-9-]*\._)$/i'],
            'egg_service_tags' => 'nullable|array',
            'egg_service_tags.*' => ['nullable', 'string', 'max:100', 'regex:/^(_?[a-z0-9][a-z0-9-]*|_[a-z0-9][a-z0-9-]*\._(?:tcp|udp)?|_[a-z0-9][a-z0-9-]*\._)$/i'],
            'wildcard_enabled' => 'sometimes|boolean',
            'enabled' => 'sometimes|boolean',
        ];
    }
}
