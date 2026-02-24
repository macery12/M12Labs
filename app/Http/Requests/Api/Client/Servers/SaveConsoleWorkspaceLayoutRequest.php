<?php

namespace Everest\Http\Requests\Api\Client\Servers;

use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class SaveConsoleWorkspaceLayoutRequest extends ClientApiRequest
{
    /**
     * Determine if the API user has permission to perform this action.
     */
    public function permission(): string
    {
        return Permission::ACTION_WEBSOCKET_CONNECT;
    }

    /**
     * Rules to validate this request against.
     */
    public function rules(): array
    {
        return [
            'version' => 'required|integer|min:1',
            'hidden' => 'required|array',
            'hidden.*' => 'string',
            'layout' => 'required|array',
            'layout.*.id' => 'required|string',
            'layout.*.x' => 'required|integer',
            'layout.*.y' => 'required|integer',
            'layout.*.w' => 'required|integer|min:1',
            'layout.*.h' => 'required|integer|min:1',
            'layout.*.minW' => 'sometimes|integer|min:1',
            'layout.*.minH' => 'sometimes|integer|min:1',
        ];
    }
}
