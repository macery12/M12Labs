<?php

namespace Everest\Http\Requests\Api\Client\Billing;

use Everest\Http\Requests\Api\Client\ClientApiRequest;

class ValidateCouponRequest extends ClientApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string',
            'subtotal' => 'required|numeric|min:0',
            'order_type' => 'nullable|string|in:new,ren,upg',
        ];
    }
}
