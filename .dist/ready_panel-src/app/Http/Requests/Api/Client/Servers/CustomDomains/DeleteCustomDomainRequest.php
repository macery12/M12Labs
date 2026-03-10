<?php

namespace Everest\Http\Requests\Api\Client\Servers\CustomDomains;

use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class DeleteCustomDomainRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_ALLOCATION_UPDATE;
    }
}
