<?php

namespace Everest\Http\Requests\Api\Client\ServerGroups;

use Everest\Models\ServerGroup;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class StoreServerGroupRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ServerGroup::$validationRules['name'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
