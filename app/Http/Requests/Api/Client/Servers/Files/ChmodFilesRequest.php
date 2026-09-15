<?php

namespace Everest\Http\Requests\Api\Client\Servers\Files;

use Everest\Models\Permission;
use Illuminate\Validation\Validator;
use Everest\Contracts\Http\ClientPermissionsRequest;
use Everest\Http\Requests\Api\Client\ClientApiRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\Concerns\SanitizesFilePaths;

class ChmodFilesRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    use SanitizesFilePaths;

    public function permission(): string
    {
        return Permission::ACTION_FILE_UPDATE;
    }

    public function rules(): array
    {
        return [
            // Optional, defaulting to the server root. "required|nullable"
            // rejected the null it advertised.
            'root' => 'sometimes|nullable|string',
            'files' => 'required|array|min:1',
            'files.*.file' => 'required|string',
            // The daemon parses this with from_str_radix(.., 8), so constrain it
            // to a real octal mode here rather than accepting any number and
            // letting the daemon skip the entry.
            // Deliberately no 'string' rule alongside it: a client sending the
            // mode as a JSON number was accepted before and still is.
            // Three octal digits, or four with a leading setuid/setgid/sticky
            // digit. Nothing else is a file mode.
            'files.*.mode' => ['required', 'regex:/^[0-7]{3,4}$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->sanitizeRootAndKeyedFileList($validator));
    }
}
