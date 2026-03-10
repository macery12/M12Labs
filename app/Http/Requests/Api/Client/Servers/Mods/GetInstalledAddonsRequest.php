<?php

namespace Everest\Http\Requests\Api\Client\Servers\Mods;

use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class GetInstalledAddonsRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_READ;
    }

    public function rules(): array
    {
        return [
            'type' => 'sometimes|string|in:mods,plugins',
            'search' => 'sometimes|string|max:200',
            'status' => 'sometimes|string|in:all,enabled,disabled',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:200',
        ];
    }
}
