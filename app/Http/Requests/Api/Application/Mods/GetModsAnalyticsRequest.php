<?php

namespace Everest\Http\Requests\Api\Application\Mods;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class GetModsAnalyticsRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::MODS_READ;
    }
}
