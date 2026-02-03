<?php

namespace Everest\Http\Requests\Api\Client\Extensions\PlayerManager;

use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class BanRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_EXTENSION_MANAGE;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|min:3|max:16',
            'reason' => 'required|string|min:3|max:255',
        ];
    }
}
