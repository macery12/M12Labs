<?php

namespace Everest\Http\Requests\Api\Client\ServerGroups;

use Everest\Http\Requests\Api\Client\ClientApiRequest;

class UpdateServerGroupRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:3'],
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
