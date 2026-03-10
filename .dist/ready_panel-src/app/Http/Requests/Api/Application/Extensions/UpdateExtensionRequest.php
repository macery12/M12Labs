<?php

namespace Everest\Http\Requests\Api\Application\Extensions;

use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateExtensionRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'enabled' => 'sometimes|boolean',
            'allowed_nests' => 'sometimes|array',
            'allowed_nests.*' => 'integer|exists:nests,id',
            'allowed_eggs' => 'sometimes|array',
            'allowed_eggs.*' => 'integer|exists:eggs,id',
            'settings' => 'sometimes|array',
        ];
    }
}
