<?php

namespace Everest\Http\Requests\Api\Client\Extensions;

use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class GetServerExtensionsRequest extends ClientApiRequest
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
