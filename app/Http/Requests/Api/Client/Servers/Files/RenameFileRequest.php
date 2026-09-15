<?php

namespace Everest\Http\Requests\Api\Client\Servers\Files;

use Everest\Models\Permission;
use Illuminate\Validation\Validator;
use Everest\Contracts\Http\ClientPermissionsRequest;
use Everest\Http\Requests\Api\Client\ClientApiRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\Concerns\SanitizesFilePaths;

class RenameFileRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    use SanitizesFilePaths;

    /**
     * The permission the user is required to have in order to perform this
     * request action.
     */
    public function permission(): string
    {
        return Permission::ACTION_FILE_UPDATE;
    }

    public function rules(): array
    {
        return [
            // `root` is optional and defaults to the server root. It used to be
            // declared "required|nullable", which rejected the null it
            // advertised — harmless for the panel's own UI, which always sends a
            // string, but a trap for anything else calling the API.
            'root' => 'sometimes|nullable|string',
            'files' => 'required|array|min:1',
            'files.*' => 'array',
            'files.*.to' => 'required|string',
            'files.*.from' => 'required|string',
        ];
    }

    /**
     * Normalize and validate rename targets to prevent path traversal.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            try {
                $root = $this->normalizeRoot($this->input('root'));
            } catch (\InvalidArgumentException $ex) {
                $validator->errors()->add('root', $ex->getMessage());

                return;
            }

            $sanitizedFiles = [];

            foreach ($this->input('files', []) as $file) {
                try {
                    // Replace inputs with sanitized values to ensure downstream
                    // safety. Both sides come back relative to the root, which
                    // is the shape the daemon joins onto it.
                    $sanitizedFiles[] = [
                        'from' => $this->sanitizeRootRelativePath($root, (string) ($file['from'] ?? '')),
                        'to' => $this->sanitizeRootRelativePath($root, (string) ($file['to'] ?? '')),
                    ];
                } catch (\InvalidArgumentException $ex) {
                    $validator->errors()->add('files', $ex->getMessage());

                    return;
                }
            }

            $this->merge([
                'root' => $root,
                'files' => $sanitizedFiles,
            ]);
        });
    }
}
