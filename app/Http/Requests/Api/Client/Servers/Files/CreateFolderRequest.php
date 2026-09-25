<?php

namespace Everest\Http\Requests\Api\Client\Servers\Files;

use Everest\Models\Permission;
use Illuminate\Validation\Validator;
use Everest\Contracts\Http\ClientPermissionsRequest;
use Everest\Http\Requests\Api\Client\ClientApiRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\Concerns\SanitizesFilePaths;

class CreateFolderRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    use SanitizesFilePaths;

    /**
     * Checks that the authenticated user is allowed to create files on the server.
     */
    public function permission(): string
    {
        return Permission::ACTION_FILE_CREATE;
    }

    public function rules(): array
    {
        return [
            'root' => 'sometimes|nullable|string',
            'name' => 'required|string',
        ];
    }

    /**
     * The daemon joins `name` onto `root`, so it is sanitized root-relative —
     * nested names like "config/backups" stay legal, traversal does not.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->sanitizeRootAndSingleFile($validator, 'name'));
    }
}
