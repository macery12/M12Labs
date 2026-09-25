<?php

namespace Everest\Http\Requests\Api\Client\Servers\Files;

use Everest\Models\Permission;
use Illuminate\Validation\Validator;
use Everest\Contracts\Http\ClientPermissionsRequest;
use Everest\Http\Requests\Api\Client\ClientApiRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\Concerns\SanitizesFilePaths;

class DeleteFileRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    use SanitizesFilePaths;

    public function permission(): string
    {
        return Permission::ACTION_FILE_DELETE;
    }

    public function rules(): array
    {
        return [
            // Optional, defaulting to the server root. "required|nullable"
            // rejected the null it advertised.
            'root' => 'sometimes|nullable|string',
            'files' => 'required|array|min:1',
            'files.*' => 'required|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->sanitizeRootAndFileList($validator));
    }
}
