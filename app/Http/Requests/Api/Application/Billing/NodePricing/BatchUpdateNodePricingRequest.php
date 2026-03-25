<?php

namespace Everest\Http\Requests\Api\Application\Billing\NodePricing;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class BatchUpdateNodePricingRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::BILLING_UPDATE;
    }

    public function rules(): array
    {
        return [
            'nodes' => ['required', 'array'],
            'nodes.*.id' => ['required', 'integer'],
            'nodes.*.price_multiplier' => ['required', 'numeric', 'min:0', 'max:5'],
            'nodes.*.price_multiplier_description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
