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
            'curseforge_api_key' => 'nullable|string|min:10|max:255',
        ];
    }

    public function normalize(): array
    {
        return $this->only(['enabled', 'curseforge_api_key']);
    }

    public function permission(): string
    {
        return AdminRole::MODS_UPDATE;
    }
}
