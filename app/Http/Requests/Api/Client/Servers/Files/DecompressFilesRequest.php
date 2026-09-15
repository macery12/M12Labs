<?php

namespace Everest\Http\Requests\Api\Client\Servers\Files;

use Illuminate\Validation\Validator;
use Everest\Http\Requests\Api\Client\Servers\Files\Concerns\SanitizesFilePaths;

class DecompressFilesRequest extends OverwriteCapableFileRequest
{
    use SanitizesFilePaths;

    public function rules(): array
    {
        return [
            'root' => 'sometimes|nullable|string',
            'file' => 'required|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->sanitizeRootAndSingleFile($validator));
    }
}
