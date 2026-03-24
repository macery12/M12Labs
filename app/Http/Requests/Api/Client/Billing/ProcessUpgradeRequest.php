<?php

namespace Everest\Http\Requests\Api\Client\Billing;

use Everest\Http\Requests\Api\Client\ClientApiRequest;

class ProcessUpgradeRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'product_id' => 'required|int|exists:products,id',
        ];
    }
}
