<?php

namespace Everest\Http\Requests\Api\Client\Servers\Files;

use Everest\Models\Permission;
use Illuminate\Validation\Validator;
use Everest\Contracts\Http\ClientPermissionsRequest;
use Everest\Http\Requests\Api\Client\ClientApiRequest;
use Everest\Http\Requests\Api\Client\Servers\Files\Concerns\SanitizesFilePaths;

class GetFileContentsRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    use SanitizesFilePaths;

    /**
     * Returns the permissions string indicating which permission should be used to
     * validate that the authenticated user has permission to perform this action aganist
     * the given resource (server).
     */
    public function permission(): string
    {
        return Permission::ACTION_FILE_READ_CONTENT;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|string',
        ];
    }

    /**
     * The root is permitted because download-directory shares this request and
     * streaming the whole server root is a legitimate target; asking for the
     * root's *contents* just fails at the daemon, as it always has.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->sanitizeAbsolutePathField($validator, 'file', allowRoot: true));
    }
}
