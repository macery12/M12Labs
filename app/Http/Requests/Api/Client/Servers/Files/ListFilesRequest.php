<?php

namespace Everest\Http\Requests\Api\Client\Servers\Files;

use Everest\Models\Permission;
use Illuminate\Validation\Validator;
use Everest\Contracts\Http\ClientPermissionsRequest;
use Everest\Http\Requests\Api\Client\ClientApiRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\Concerns\SanitizesFilePaths;

class ListFilesRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    use SanitizesFilePaths;

    /**
     * Checks that the authenticated user is allowed to list files on the server.
     */
    public function permission(): string
    {
        return Permission::ACTION_FILE_READ;
    }

    public function rules(): array
    {
        return [
            'directory' => 'sometimes|nullable|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            try {
                $this->merge(['directory' => $this->normalizeRoot($this->input('directory'))]);
            } catch (\InvalidArgumentException $ex) {
                $validator->errors()->add('directory', $ex->getMessage());
            }
        });
    }
}
