<?php

namespace Everest\Services\Billing;

use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use Everest\Models\Billing\Product;
use Everest\Services\Security\LogSanitizer;
use Everest\Models\Billing\BillingException;
use Everest\Exceptions\Billing\BillingException as BillingExceptionClass;
use Everest\Models\Setting;

class MolliePaymentService
{
    private ?MollieApiClient $mollie = null;

    public function __construct()
    {
        $mollieKey = Setting::get('settings::modules:billing:mollie:api_key', config('modules.billing.mollie.api_key'));
        if ($mollieKey) {
            $this->mollie = new MollieApiClient();
            $this->mollie->setApiKey($mollieKey);
        }
    }

    /**
     * Create a Mollie payment.
     *
     * @throws BillingExceptionClass
     */
    public function createPayment(Product $product, float $amount, ?int $couponId, string $returnUrl, string $webhookToken): Payment
    {
        $this->ensureMollieInitialized();

        try {
            $payment = $this->mollie->payments->create([
                'amount' => [
                    'currency' => strtoupper(config('modules.billing.currency.code')),
                    'value' => number_format($amount, 2, '.', ''),
                ],
                'description' => 'Order for ' . $product->name,
                'redirectUrl' => $returnUrl,
                'webhookUrl' => route('webhook.mollie', ['token' => $webhookToken]),
                'metadata' => [
                    'product_id' => (string) $product->id,
                    'coupon_id' => (string) ($couponId ?? ''),
                ],
            ]);

            return $payment;
        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            \Log::error('Mollie payment creation failed', array_merge([
                'product_id' => $product->id,
                'amount' => $amount,
            ], LogSanitizer::exceptionContext($e)));

            throw new BillingExceptionClass('Mollie payment creation failed', 'Failed to create Mollie payment: ' . $e->getMessage(), BillingException::TYPE_PAYMENT, null, 'mollie', null, ['product_id' => $product->id, 'amount' => $amount, 'error' => $e->getMessage()], $e);
        } catch (\Exception $e) {
            \Log::error('Mollie payment creation exception', array_merge([
                'product_id' => $product->id,
            ], LogSanitizer::exceptionContext($e)));

            throw new BillingExceptionClass('Mollie payment creation error', 'An unexpected error occurred while creating Mollie payment: ' . $e->getMessage(), BillingException::TYPE_PAYMENT, null, 'mollie', null, ['product_id' => $product->id, 'error' => $e->getMessage()], $e);
        }
    }

    /**
     * Get a Mollie payment by ID.
     *
     * Callers should invoke status methods (->isPaid(), ->isExpired(), etc.) directly
     * on the returned Payment object rather than delegating through this service.
     *
     * @throws BillingExceptionClass
     */
    public function getPayment(string $paymentId): Payment
    {
        $this->ensureMollieInitialized();

        try {
            return $this->mollie->payments->get($paymentId);
        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            \Log::error('Mollie payment fetch failed', array_merge([
                'payment_id' => LogSanitizer::maskIdentifier($paymentId),
            ], LogSanitizer::exceptionContext($e)));

            throw new BillingExceptionClass('Mollie payment fetch failed', 'Failed to fetch Mollie payment: ' . $e->getMessage(), BillingException::TYPE_PAYMENT, null, 'mollie', $paymentId, ['error' => $e->getMessage()], $e);
        } catch (\Exception $e) {
            \Log::error('Mollie payment fetch exception', array_merge([
                'payment_id' => LogSanitizer::maskIdentifier($paymentId),
            ], LogSanitizer::exceptionContext($e)));

            throw new BillingExceptionClass('Mollie payment fetch error', 'An unexpected error occurred while fetching Mollie payment: ' . $e->getMessage(), BillingException::TYPE_PAYMENT, null, 'mollie', $paymentId, ['error' => $e->getMessage()], $e);
        }
    }

    /**
     * Get the current status string of a payment.
     *
     * Returns one of: open, pending, paid, failed, expired, canceled, authorized
     */
    public function getPaymentStatus(string $paymentId): string
    {
        return $this->getPayment($paymentId)->status;
    }

    /**
     * Get the checkout URL for a payment.
     */
    public function getCheckoutUrl(string $paymentId): string
    {
        $payment = $this->getPayment($paymentId);

        return $payment->getCheckoutUrl();
    }

    /**
     * Ensure Mollie client is initialized.
     *
     * @throws BillingExceptionClass if Mollie is not configured
     */
    private function ensureMollieInitialized(): void
    {
        if (!$this->mollie) {
            throw new BillingExceptionClass('Mollie API key is missing', 'Mollie is not configured. Please add your Mollie API key.', BillingException::TYPE_STOREFRONT, null, 'mollie', null, ['configured' => false]);
        }
    }
}
