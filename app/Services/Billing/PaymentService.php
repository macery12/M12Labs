<?php

namespace Everest\Services\Billing;

use Everest\Models\User;
use Stripe\StripeClient;
use Everest\Models\Billing\Product;

class PaymentService
{
    /**
     * Create a new Stripe checkout and return the URL as an object.
     */
    public function create(StripeClient $stripe, User $user, Product $product, array $metadata, ?float $price = null): object
    {
        $transaction = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'customer_email' => $user->email,

            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower(config('modules.billing.currency.code')),
                    'product_data' => [
                        'name' => $product->name,
                    ],
                    'unit_amount' => (int) round(($price ?? $product->price) * 100),
                ],
                'quantity' => 1,
            ]],

            'success_url' => config('app.url') . '/account/billing/processing?session={CHECKOUT_SESSION_ID}',
            'cancel_url' => config('app.url') . '/account/billing/cancel',

            'metadata' => $metadata,
        ]);

        return $transaction;
    }
}
