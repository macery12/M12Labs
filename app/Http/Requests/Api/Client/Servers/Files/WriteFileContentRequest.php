<?php

namespace Everest\Http\Requests\Api\Client\Servers\Files;

use Illuminate\Validation\Validator;
use Everest\Http\Requests\Api\Client\Servers\Files\Concerns\SanitizesFilePaths;

class WriteFileContentRequest extends OverwriteCapableFileRequest
{
    use SanitizesFilePaths;

    /**
     * There is no rule here for the file contents since we just use the body content
     * on the request to set the file contents. If nothing is passed that is fine since
     * it just means we want to set the file to be empty.
     */
    public function rules(): array
    {
        return [
            'file' => 'required|string|max:' . WriteFileWithDiffRequest::MAX_FILE_PATH_LENGTH,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->sanitizeAbsolutePathField($validator, 'file'));
    }
}
