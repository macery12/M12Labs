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
            'default_source' => 'nullable|string|in:modrinth,spigot,spiget',
            'spiget_enabled' => 'nullable|bool',
            'allow_external_downloads' => 'nullable|bool',
        ];
    }

    public function permission(): string
    {
        return AdminRole::MODS_UPDATE;
    }
}
