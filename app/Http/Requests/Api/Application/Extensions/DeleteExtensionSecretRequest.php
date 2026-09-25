<?php

namespace Everest\Http\Requests\Api\Application\Extensions;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

/**
 * Clearing one credential is a configuration change, not a removal of the
 * extension, so it sits under extensions.update rather than extensions.delete.
 */
class DeleteExtensionSecretRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [];
    }

    public function permission(): string
    {
        return AdminRole::EXTENSIONS_UPDATE;
    }
}
