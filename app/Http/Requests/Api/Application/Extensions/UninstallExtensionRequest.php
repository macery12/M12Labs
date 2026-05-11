<?php

namespace Everest\Http\Requests\Api\Application\Extensions;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UninstallExtensionRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [];
    }

    public function permission(): string
    {
        return AdminRole::EXTENSIONS_DELETE;
    }
}