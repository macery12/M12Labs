<?php

namespace Everest\Http\Requests\Api\Application\Extensions;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateExtensionSecretRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            // Write-only. An empty string is accepted and means "leave the
            // stored value alone", which is what a blind admin form submits
            // when the operator did not touch the field.
            'value' => 'present|string|max:8192',
        ];
    }

    public function permission(): string
    {
        return AdminRole::EXTENSIONS_UPDATE;
    }
}
