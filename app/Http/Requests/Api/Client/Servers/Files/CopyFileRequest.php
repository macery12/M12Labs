<?php

namespace Everest\Http\Requests\Api\Client\Servers\Files;

use Everest\Models\Permission;
use Illuminate\Validation\Validator;
use Everest\Contracts\Http\ClientPermissionsRequest;
use Everest\Http\Requests\Api\Client\ClientApiRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\Concerns\SanitizesFilePaths;

class CopyFileRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    use SanitizesFilePaths;

    public function permission(): string
    {
        return Permission::ACTION_FILE_CREATE;
    }

    public function rules(): array
    {
        return [
            'location' => 'required|string',
        ];
    }

    /**
     * `location` is a whole path from the server root — the daemon splits its
     * own parent and file name — so it is sanitized as server-absolute rather
     * than relative to a root.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->sanitizeAbsolutePathField($validator, 'location'));
    }
}
