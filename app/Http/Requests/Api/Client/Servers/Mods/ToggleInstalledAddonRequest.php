<?php

namespace Everest\Http\Requests\Api\Client\Servers\Mods;

use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class ToggleInstalledAddonRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_UPDATE;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|string|in:mods,plugins',
            'path' => 'required|string',
            'enable' => 'required|boolean',
        ];
    }
}
