<?php

namespace Everest\Http\Requests\Api\Application\Extensions;

use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class BatchUninstallExtensionRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'extension_ids'   => 'required|array|min:1|max:50',
            'extension_ids.*' => 'required|string|max:191',
        ];
    }
}
