<?php

namespace Everest\Http\Requests\Api\Client\Servers\Mods;

use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class SearchModsRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_CREATE;
    }

    public function rules(): array
    {
        return [
            'searchFilter' => 'nullable|string|max:255',
            'sortField' => 'nullable|string|in:1,2,3,4,5,6', // 1=Featured, 2=Popularity, 3=LastUpdated, 4=Name, 5=Author, 6=TotalDownloads
            'sortOrder' => 'nullable|string|in:asc,desc',
            'gameVersion' => 'nullable|string|max:50',
            'modLoaderType' => 'nullable|integer|min:0', // Mod loader IDs from CurseForge API
            'pageSize' => 'nullable|integer|min:1|max:50',
            'index' => 'nullable|integer|min:0',
        ];
    }
}
