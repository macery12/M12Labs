<?php

namespace Everest\Http\Requests\Api\Application\CustomDomains;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class GetCustomDomainSettingsRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::CUSTOM_DOMAINS_READ;
    }
}
