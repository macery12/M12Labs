<?php

namespace Everest\Http\Requests\Api\Application\Billing\DiscountCodes;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class DeleteDiscountCodeRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::BILLING_DISCOUNT_CODES;
    }
}
