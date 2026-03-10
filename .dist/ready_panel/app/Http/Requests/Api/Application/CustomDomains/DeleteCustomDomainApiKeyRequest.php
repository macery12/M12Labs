<?php

namespace Everest\Http\Requests\Api\Application\CustomDomains;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class DeleteCustomDomainApiKeyRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::BILLING_UPDATE;
    }
}
