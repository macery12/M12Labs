<?php

namespace Everest\Http\Requests\Api\Client\Billing;

use Everest\Http\Requests\Api\Client\ClientApiRequest;

class UpdateBillingProfileRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'first_name'   => ['required', 'string', 'max:100'],
            'last_name'    => ['required', 'string', 'max:100'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city'         => ['required', 'string', 'max:100'],
            'state'        => ['required', 'string', 'max:100'],
            'postal_code'  => ['required', 'string', 'max:20'],
            'country'      => ['required', 'string', 'size:2'],
            'phone'        => ['nullable', 'string', 'max:30'],
        ];
    }
}
