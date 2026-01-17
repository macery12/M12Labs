<?php

namespace Everest\Services\Billing;

use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\Product;
use Everest\Models\Billing\BillingException;
use Everest\Exceptions\DisplayException;

class MolliePaymentService
{
    private ?MollieApiClient $mollie = null;

    public function __construct()
    {
        $mollieKey = config('modules.billing.mollie.api_key');
        if ($mollieKey) {
            $this->mollie = new MollieApiClient();
            $this->mollie->setApiKey($mollieKey);
        }
    }

    /**
     * Create a Mollie payment.
     *
     * @param Product $product
     * @param float $amount
     * @param int|null $couponId
     * @param string $returnUrl
     * @return Payment
     */
    public function createPayment(Product $product, float $amount, ?int $couponId, string $returnUrl): Payment
    {
        $this->ensureMollieInitialized();

        $payment = $this->mollie->payments->create([
            'amount' => [
                'currency' => strtoupper(config('modules.billing.currency.code')),
                'value' => number_format($amount, 2, '.', ''),
            ],
            'description' => 'Order for ' . $product->name,
            'redirectUrl' => $returnUrl,
            'webhookUrl' => route('api:client:billing:mollie:webhook'),
            'metadata' => [
                'product_id' => (string) $product->id,
                'coupon_id' => (string) ($couponId ?? ''),
            ],
        ]);

        return $payment;
    }

    /**
     * Get a Mollie payment by ID.
     *
     * @param string $paymentId
     * @return Payment
     */
    public function getPayment(string $paymentId): Payment
    {
        $this->ensureMollieInitialized();
        
        return $this->mollie->payments->get($paymentId);
    }

    /**
     * Update payment metadata.
     *
     * @param string $paymentId
     * @param array $metadata
     * @return void
     */
    public function updatePaymentMetadata(string $paymentId, array $metadata): void
    {
        // Mollie doesn't support updating payment metadata after creation
        // Metadata must be set during payment creation
    }

    /**
     * Check if a payment is paid.
     *
     * @param string $paymentId
     * @return bool
     */
    public function isPaymentPaid(string $paymentId): bool
    {
        $payment = $this->getPayment($paymentId);
        return $payment->isPaid();
    }

    /**
     * Check if a payment has failed.
     *
     * @param string $paymentId
     * @return bool
     */
    public function isPaymentFailed(string $paymentId): bool
    {
        $payment = $this->getPayment($paymentId);
        return $payment->isFailed() || $payment->isExpired() || $payment->isCanceled();
    }

    /**
     * Get the checkout URL for a payment.
     *
     * @param string $paymentId
     * @return string
     */
    public function getCheckoutUrl(string $paymentId): string
    {
        $payment = $this->getPayment($paymentId);
        return $payment->getCheckoutUrl();
    }

    /**
     * Ensure Mollie client is initialized.
     *
     * @throws DisplayException if Mollie is not configured
     */
    private function ensureMollieInitialized(): void
    {
        if (!$this->mollie) {
            BillingException::create([
                'exception_type' => BillingException::TYPE_STOREFRONT,
                'title' => 'Mollie API key is missing',
                'description' => 'Add the Mollie API key to your billing configuration',
            ]);
            throw new DisplayException('Mollie is not configured. Please add your Mollie API key.');
        }
    }
}
