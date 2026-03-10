<?php

namespace Everest\Http\Requests\Api\Application\Billing\CustomDomains;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class DeleteCustomDomainRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::BILLING_UPDATE;
    }
}
