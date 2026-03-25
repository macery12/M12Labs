<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Everest\Exceptions\DisplayException;
use Everest\Models\Billing\DiscountCode;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Transformers\Api\Client\DiscountCodeTransformer;
use Everest\Http\Requests\Api\Client\Billing\ValidateDiscountCodeRequest;

class DiscountCodeController extends ClientApiController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Returns all the variables of an egg.
     */
    public function index(ValidateDiscountCodeRequest $request): array
    {
        $discount_code = DiscountCode::where('code', $request->input('discount_code'))->first();

        if (!$discount_code) {
            throw new DisplayException('The selected discount code does not exist.');
        }

        if (!$discount_code->isValid()) {
            throw new DisplayException('The selected discount code is invalid.');
        }

        return $this->fractal->item($discount_code)
            ->transformWith(DiscountCodeTransformer::class)
            ->toArray();
    }
}
