<?php

namespace Everest\Http\Requests\Api\Client\Extensions\PlayerManager;

use Everest\Http\Requests\Api\Client\ClientApiRequest;

class AttributeRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'value' => 'required|numeric',
        ];
    }
}
