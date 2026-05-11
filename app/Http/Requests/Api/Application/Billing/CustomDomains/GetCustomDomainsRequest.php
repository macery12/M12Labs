<?php

namespace Everest\Http\Requests\Api\Application\Billing\CustomDomains;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class GetCustomDomainsRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::CUSTOM_DOMAINS_READ;
    }
}
