<?php

namespace Everest\Http\Requests\Api\Application\Billing;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateBillingSettingsRequest extends ApplicationApiRequest
{
    public function permission(): string
    {
        return AdminRole::BILLING_UPDATE;
    }

    public function rules(): array
    {
        $key = $this->input('key');
        $value = $this->input('value');

        // Add validation for Stripe API keys to prevent wrong keys in wrong fields
        if ($key === 'keys:publishable') {
            return [
                'value' => [
                    'required',
                    'string',
                    'min:100',
                    'max:120',
                    function ($attribute, $value, $fail) {
                        if (str_starts_with($value, 'sk_')) {
                            $fail('The publishable key field contains a SECRET key. Please use your publishable key (starts with pk_) here.');
                        }
                        if (!str_starts_with($value, 'pk_')) {
                            $fail('The publishable key must start with pk_test_ or pk_live_');
                        }
                    },
                ],
            ];
        }

        if ($key === 'keys:secret') {
            return [
                'value' => [
                    'required',
                    'string',
                    'min:100',
                    'max:120',
                    function ($attribute, $value, $fail) {
                        if (str_starts_with($value, 'pk_')) {
                            $fail('The secret key field contains a PUBLISHABLE key. Please use your secret key (starts with sk_) here.');
                        }
                        if (!str_starts_with($value, 'sk_')) {
                            $fail('The secret key must start with sk_test_ or sk_live_');
                        }
                    },
                ],
            ];
        }

        return [];
    }
}
