<?php

namespace Everest\Http\Requests\Api\Application\Extensions;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateExtensionSettingsRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'key' => 'required|string|max:191',
            'value' => 'present',
        ];
    }

    public function permission(): string
    {
        return AdminRole::EXTENSIONS_UPDATE;
    }
}
