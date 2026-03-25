<?php

namespace Everest\Http\Requests\Api\Client\ServerGroups;

use Everest\Http\Requests\Api\Client\ClientApiRequest;

class RemoveServerFromGroupRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'server' => ['required', 'string'],
        ];
    }
}
