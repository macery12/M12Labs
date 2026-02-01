<?php

namespace Everest\Http\Requests\Api\Application\Billing\BillingCycles;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateBillingCycleRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::BILLING_WRITE;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|min:1|max:191',
            'durationDays' => 'sometimes|required|integer|min:1',
            'sortOrder' => 'nullable|integer',
            'isActive' => 'nullable|boolean',
        ];
    }
}
