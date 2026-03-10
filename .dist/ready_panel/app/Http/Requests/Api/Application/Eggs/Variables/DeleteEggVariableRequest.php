<?php

namespace Everest\Http\Requests\Api\Application\Eggs\Variables;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class DeleteEggVariableRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::EGGS_DELETE;
    }
}
