<?php

namespace Everest\Http\Requests\Api\Client\Extensions\PlayerManager;

use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class WhisperRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_EXTENSION_MANAGE;
    }

    public function rules(): array
    {
        return [
            'uuid' => 'required|string|max:40',
            'message' => 'required|string|min:1|max:255',
        ];
    }
}
