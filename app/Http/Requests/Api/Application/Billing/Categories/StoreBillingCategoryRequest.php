<?php

namespace Everest\Http\Requests\Api\Application\Billing\Categories;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class StoreBillingCategoryRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::BILLING_CATEGORIES_CREATE;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|min:3|max:191',
            'icon' => 'nullable|string|max:300',
            'description' => 'nullable|string|max:300',
            'visible' => 'nullable|boolean',
            'eggId' => 'required|integer|exists:eggs,id',
            'allowedEggs' => 'nullable|array',
            'allowedEggs.*' => 'integer|exists:eggs,id',
            'allowEggChanges' => 'nullable|boolean',
            'allowPlanChanges' => 'nullable|boolean',
        ];
    }
}
