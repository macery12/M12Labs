<?php

namespace Everest\Http\Requests\Api\Application\Extensions;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UninstallExtensionRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'drop_data' => 'sometimes|boolean',
            // Destructive data drops require the caller to echo the extension
            // id back, mirroring the CLI's typed confirmation.
            'confirm' => 'required_if:drop_data,true|string',
            // Proceed even though tracked files no longer match what was
            // installed, discarding those local edits. Not a security control:
            // the files are already on disk and already executing. It exists so
            // drift — a code formatter run over the panel tree is the usual
            // cause — cannot strand a package with no way to update or remove
            // it.
            'acknowledge_modified_files' => 'sometimes|boolean',
        ];
    }

    public function permission(): string
    {
        return AdminRole::EXTENSIONS_DELETE;
    }
}
