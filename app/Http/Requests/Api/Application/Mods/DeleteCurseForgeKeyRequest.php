<?php

namespace Everest\Http\Requests\Api\Application\Mods;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class DeleteCurseForgeKeyRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::MODS_UPDATE ?? 'admin.mods.update';
    }
}
