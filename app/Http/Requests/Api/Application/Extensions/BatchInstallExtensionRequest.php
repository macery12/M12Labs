<?php

namespace Everest\Http\Requests\Api\Application\Extensions;

use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class BatchInstallExtensionRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'extensions'                  => 'required|array|min:1|max:50',
            'extensions.*.extension_id'   => 'required|string|max:191',
            'extensions.*.repository_id'  => 'required|integer|exists:extension_repositories,id',
            'extensions.*.version'        => 'nullable|string|max:191',
        ];
    }
}
