<?php

namespace Everest\Http\Requests\Api\Client\Servers\Startup;

use Everest\Models\Permission;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class GetStartupVariableVersionsRequest extends ClientApiRequest
{
    public function validationData(): array
    {
        $data = parent::validationData();

        if (array_key_exists('include_snapshots', $data)) {
            $normalized = filter_var($data['include_snapshots'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $data['include_snapshots'] = $normalized;
        }

        return $data;
    }

    public function permission(): string
    {
        return Permission::ACTION_STARTUP_READ;
    }

    public function rules(): array
    {
        return [
            'key' => 'required|string|max:191',
            'include_snapshots' => 'sometimes|boolean',
            'context' => 'sometimes|array',
            'context.*' => 'nullable|string|max:191',
        ];
    }
}
