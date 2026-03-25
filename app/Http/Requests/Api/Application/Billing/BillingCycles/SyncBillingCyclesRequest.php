<?php

namespace Everest\Http\Requests\Api\Application\Billing\BillingCycles;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class SyncBillingCyclesRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::BILLING_UPDATE;
    }

    public function rules(): array
    {
        return [
            'cycles' => ['required', 'array'],
            'cycles.*.days' => ['required', 'integer', 'min:1', 'max:365'],
            'cycles.*.is_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
