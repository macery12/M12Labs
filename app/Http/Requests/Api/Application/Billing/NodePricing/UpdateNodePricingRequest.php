<?php

namespace Everest\Http\Requests\Api\Application\Billing\NodePricing;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateNodePricingRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::BILLING_UPDATE;
    }

    public function rules(): array
    {
        return [
            'price_multiplier' => ['required', 'numeric', 'min:0', 'max:5'],
            'price_multiplier_description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
