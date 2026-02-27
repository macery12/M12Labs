<?php

namespace Everest\Http\Requests\Api\Application\Mods;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateModsSettingsRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'enabled' => 'nullable|bool',
            'default_source' => 'nullable|string|in:modrinth,curseforge,spigot,spiget',
            'curseforge_api_key' => 'nullable|string|min:10|max:255',
            'spiget_enabled' => 'nullable|bool',
        ];
    }

    public function permission(): string
    {
        return AdminRole::MODS_UPDATE;
    }
}
