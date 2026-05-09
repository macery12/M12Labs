<?php

namespace Everest\Http\Requests\Api\Application\Plugins;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdatePluginProviderRulesRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'provider_key' => 'required|string',
            'enabled_global' => 'required|boolean',
            'allowed_nest_ids' => 'array',
            'allowed_nest_ids.*' => 'integer',
            'allowed_egg_ids' => 'array',
            'allowed_egg_ids.*' => 'integer',
        ];
    }

    public function permission(): string
    {
        return AdminRole::MODS_UPDATE;
    }
}
