<?php

namespace Everest\Http\Requests\Api\Application\Extensions;

use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class InstallExtensionRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'repository_id' => 'required|integer|exists:extension_repositories,id',
            'version' => 'nullable|string|max:191',
        ];
    }
}