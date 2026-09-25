<?php

namespace Everest\Http\Requests\Api\Application\Extensions;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

/**
 * Authorizes and validates replacing an installed extension package.
 *
 * Package updates are distinct from new installs: an administrator may be
 * trusted to apply reviewed upgrades without being allowed to add arbitrary
 * new packages to the panel.
 */
class UpdateExtensionPackageRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'repository_id' => 'required|integer|exists:extension_repositories,id',
            'version' => 'nullable|string|max:191',
            'approved_capability_hash' => 'nullable|string|size:64',
            'acknowledge_modified_files' => 'sometimes|boolean',
        ];
    }

    public function permission(): string
    {
        return AdminRole::EXTENSIONS_UPDATE;
    }
}
