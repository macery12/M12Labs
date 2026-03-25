<?php

namespace Everest\Http\Requests\Api\Application\Billing\NodePricing;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class ResetAllNodePricingRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::BILLING_UPDATE;
    }
}
