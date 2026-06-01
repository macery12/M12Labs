<?php

namespace Everest\Http\Requests\Api\Application\Billing\Config;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class ImportBillingConfigRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::BILLING_IMPORT;
    }

    public function rules(): array
    {
        return [
            'data' => 'required|array',
            'data.categories' => 'required|array',
            'data.products' => 'required|array',
            'override' => 'sometimes|boolean',
            'ignore_duplicates' => 'sometimes|boolean',

            'resolution' => 'sometimes|array',
            'resolution.categories' => 'sometimes|array',
            'resolution.categories.*.nest_id' => 'nullable|integer',
            'resolution.categories.*.egg_id' => 'nullable|integer',
            'resolution.categories.*.allowed_eggs' => 'nullable|array',
            'resolution.categories.*.allowed_eggs.*' => 'integer',
            'resolution.categories.*.drop_products' => 'nullable|array',
            'resolution.categories.*.drop_products.*' => 'string',
            'resolution.categories.*.drop_category' => 'sometimes|boolean',
        ];
    }
}
