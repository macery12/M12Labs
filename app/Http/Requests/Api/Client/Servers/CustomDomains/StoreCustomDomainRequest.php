<?php

namespace Everest\Http\Requests\Api\Client\Servers\CustomDomains;

use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class StoreCustomDomainRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_ALLOCATION_UPDATE;
    }

    public function rules(): array
    {
        return [
            'domain_id' => 'required|integer|exists:custom_domains,id',
            'subdomain' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i'],
            'port' => 'required|integer|min:1|max:65535',
            'protocol' => 'required|in:tcp,udp,both',
            'record_type' => 'nullable|in:srv,cname',
            'service_tag' => ['nullable', 'string', 'max:100', 'regex:/^(_?[a-z0-9][a-z0-9-]*|_[a-z0-9][a-z0-9-]*\._(?:tcp|udp)?|_[a-z0-9][a-z0-9-]*\._)$/i'],
        ];
    }
}
