<?php

namespace Everest\Http\Requests\Api\Client\Servers\Files;

use Everest\Models\Permission;
use Everest\Contracts\Http\ClientPermissionsRequest;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class WriteFileWithDiffRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    /**
     * Returns the permissions string indicating which permission should be used to
     * validate that the authenticated user has permission to perform this action against
     * the given resource (server).
     */
    public function permission(): string
    {
        return Permission::ACTION_FILE_CREATE;
    }

    /**
     * Validation rules for writing a file with diff tracking.
     */
    public function rules(): array
    {
        return [
            'file' => 'required|string',
            'content' => 'present|string',
            'original_content' => 'nullable|string',
        ];
    }
}
