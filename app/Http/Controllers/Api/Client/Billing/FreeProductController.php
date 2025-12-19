<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Everest\Models\Server;
use Illuminate\Http\Request;
use Everest\Models\Billing\Product;
use Everest\Exceptions\DisplayException;
use Everest\Services\Billing\OrderProcessorService;
use Everest\Services\Billing\BillingValidationService;
use Everest\Transformers\Api\Client\ServerTransformer;
use Everest\Http\Controllers\Api\Client\ClientApiController;

class FreeProductController extends ClientApiController
{
    public function __construct(
        private BillingValidationService $validationService,
        private OrderProcessorService $processorService,
    ) {
        parent::__construct();
    }

    /**
     * Process and validate the creation of a server
     * based off of a free product in the billing portal.
     */
    public function process(Request $request): array
    {
        $user = $request->user();
        $product = Product::findOrFail($request->input('product'));

        // Validate billing is enabled
        $this->validationService->validateBillingEnabled();

        // Calculate price with coupon
        $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
        $priceInfo = $this->validationService->calculatePriceWithCoupon($product, $couponId);

        // Validate this is a free order
        $this->validationService->validatePriceType($priceInfo['finalPrice'], true);

        // Validate user doesn't already own this free product
        $this->validationService->validateFreeProductOwnership($user->id, $product);

        // Validate node deployment
        $nodeId = (int) $request->input('node');
        $this->validationService->validateNodeDeployment($nodeId, true);

        // Validate and get egg ID
        $requestedEggId = $request->input('egg_id') ? (int) $request->input('egg_id') : null;
        $eggId = $this->validationService->validateAndGetEggId($product, $requestedEggId);

        // Process the order
        $variables = $request->input('variables', []);
        $result = $this->processorService->createServerOrder(
            $request,
            $user,
            $product,
            $nodeId,
            $eggId,
            $couponId,
            $variables
        );

        return $this->fractal->item($result['server'])
            ->transformWith(ServerTransformer::class)
            ->toArray();
    }

    /**
     * Renew a free server by extending its renewal date.
     */
    public function renew(Request $request): array
    {
        $user = $request->user();
        $serverId = (int) $request->input('server_id');
        $product = Product::findOrFail($request->input('product'));

        // Validate billing is enabled
        $this->validationService->validateBillingEnabled();

        // Calculate price with coupon
        $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
        $priceInfo = $this->validationService->calculatePriceWithCoupon($product, $couponId);

        // Validate this is a free renewal
        $this->validationService->validatePriceType($priceInfo['finalPrice'], true);

        // Lookup server scoped to the authenticated user
        $server = $user->servers()->findOrFail($serverId);

        // Process the renewal
        $result = $this->processorService->processRenewal($server, $product, $couponId);

        return $this->fractal->item($result['server'])
            ->transformWith(ServerTransformer::class)
            ->toArray();
    }
}
