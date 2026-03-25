<?php

namespace Everest\Http\Requests\Api\Application\Billing\Donations;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class GetDonationsRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::BILLING_READ;
    }
}
