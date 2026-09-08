<?php

namespace Everest\Http\Requests\Api\Application\Extensions;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class InstallExtensionRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'repository_id' => 'required|integer|exists:extension_repositories,id',
            'version' => 'nullable|string|max:191',
            // Consent to the capabilities this release declares. Omitted on the
            // first attempt; the 409 that follows carries the hash to send back.
            'approved_capability_hash' => 'nullable|string|size:64',
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
        return AdminRole::EXTENSIONS_INSTALL;
    }
}
