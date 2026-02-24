<?php

namespace Everest\Http\Requests\Api\Client\Servers;

use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class ConsoleWorkspaceAccessRequest extends ClientApiRequest
{
    /**
     * Determine if the API user has permission to view or update the console workspace layout.
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
        return [];
    }
}
