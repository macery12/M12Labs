<?php

namespace Everest\Http\Requests\Api\Client\Extensions\PlayerManager;

use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class GetStatusRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_EXTENSION_READ;
    }

    public function rules(): array
    {
        return [];
    }
}
