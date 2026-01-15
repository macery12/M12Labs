<?php

namespace Everest\Http\Requests\Api\Client\Servers\Mods;

use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class GetModFilesRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_READ;
    }

    public function rules(): array
    {
        return [
            'gameVersion' => 'nullable|string|max:50',
            'modLoaderType' => 'nullable|integer|in:0,1,2,3,4,5,6',
            'pageSize' => 'nullable|integer|min:1|max:50',
            'index' => 'nullable|integer|min:0',
        ];
    }
}
