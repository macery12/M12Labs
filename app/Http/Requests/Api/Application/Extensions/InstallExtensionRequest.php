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
        ];
    }

    public function permission(): string
    {
        return AdminRole::EXTENSIONS_INSTALL;
    }
}
