<?php

namespace Everest\Http\Requests\Api\Application\Servers;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class ToggleModsRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'mods_enabled' => 'required|boolean',
        ];
    }

    public function permission(): string
    {
        return AdminRole::SERVER_UPDATE;
    }
}
