<?php

namespace Everest\Extensions\Packages\minecraft_player_manager\Http\Requests;

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
