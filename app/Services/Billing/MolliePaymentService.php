<?php

namespace Everest\Services\Billing;

use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\Product;
use Everest\Models\Billing\BillingException;
use Everest\Exceptions\Billing\BillingException as BillingExceptionClass;
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
     * @throws BillingExceptionClass
     */
    public function createPayment(Product $product, float $amount, ?int $couponId, string $returnUrl): Payment
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
                'webhookUrl' => route('api:client:billing:mollie:webhook'),
                'metadata' => [
                    'product_id' => (string) $product->id,
                    'coupon_id' => (string) ($couponId ?? ''),
                ],
            ]);

            return $payment;
        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            \Log::error('Mollie payment creation failed', [
                'product_id' => $product->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            
            throw new BillingExceptionClass(
                'Mollie payment creation failed',
                'Failed to create Mollie payment: ' . $e->getMessage(),
                BillingException::TYPE_PAYMENT,
                null,
                'mollie',
                null,
                ['product_id' => $product->id, 'amount' => $amount, 'error' => $e->getMessage()],
                $e
            );
        } catch (\Exception $e) {
            \Log::error('Mollie payment creation exception', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            
            throw new BillingExceptionClass(
                'Mollie payment creation error',
                'An unexpected error occurred while creating Mollie payment: ' . $e->getMessage(),
                BillingException::TYPE_PAYMENT,
                null,
                'mollie',
                null,
                ['product_id' => $product->id, 'error' => $e->getMessage()],
                $e
            );
        }
    }

    /**
     * Get a Mollie payment by ID.
     *
     * @param string $paymentId
     * @return Payment
     * @throws BillingExceptionClass
     */
    public function getPayment(string $paymentId): Payment
    {
        $this->ensureMollieInitialized();
        
        try {
            return $this->mollie->payments->get($paymentId);
        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            \Log::error('Mollie payment fetch failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
            
            throw new BillingExceptionClass(
                'Mollie payment fetch failed',
                'Failed to fetch Mollie payment: ' . $e->getMessage(),
                BillingException::TYPE_PAYMENT,
                null,
                'mollie',
                $paymentId,
                ['error' => $e->getMessage()],
                $e
            );
        } catch (\Exception $e) {
            \Log::error('Mollie payment fetch exception', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
            
            throw new BillingExceptionClass(
                'Mollie payment fetch error',
                'An unexpected error occurred while fetching Mollie payment: ' . $e->getMessage(),
                BillingException::TYPE_PAYMENT,
                null,
                'mollie',
                $paymentId,
                ['error' => $e->getMessage()],
                $e
            );
        }
    }

    /**
     * Update payment metadata.
     * 
     * Note: Mollie doesn't support updating payment metadata after creation.
     * Metadata must be set during payment creation.
     *
     * @param string $paymentId
     * @param array $metadata
     * @throws DisplayException
     * @return void
     */
    public function updatePaymentMetadata(string $paymentId, array $metadata): void
    {
        throw new DisplayException('Mollie does not support updating payment metadata after creation.');
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
     * Get the current status of a payment.
     * 
     * Returns one of: open, pending, paid, failed, expired, canceled, authorized
     *
     * @param string $paymentId
     * @return string
     */
    public function getPaymentStatus(string $paymentId): string
    {
        $payment = $this->getPayment($paymentId);
        return $payment->status;
    }

    /**
     * Check if payment is expired.
     *
     * @param string $paymentId
     * @return bool
     */
    public function isPaymentExpired(string $paymentId): bool
    {
        $payment = $this->getPayment($paymentId);
        return $payment->isExpired();
    }

    /**
     * Check if payment is canceled.
     *
     * @param string $paymentId
     * @return bool
     */
    public function isPaymentCanceled(string $paymentId): bool
    {
        $payment = $this->getPayment($paymentId);
        return $payment->isCanceled();
    }

    /**
     * Check if payment is authorized (but not yet captured).
     *
     * @param string $paymentId
     * @return bool
     */
    public function isPaymentAuthorized(string $paymentId): bool
    {
        $payment = $this->getPayment($paymentId);
        return $payment->isAuthorized();
    }

    /**
     * Check if payment is pending.
     *
     * @param string $paymentId
     * @return bool
     */
    public function isPaymentPending(string $paymentId): bool
    {
        $payment = $this->getPayment($paymentId);
        return $payment->isPending();
    }

    /**
     * Check if payment is open (created but no action taken).
     *
     * @param string $paymentId
     * @return bool
     */
    public function isPaymentOpen(string $paymentId): bool
    {
        $payment = $this->getPayment($paymentId);
        return $payment->isOpen();
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
     * @throws BillingExceptionClass if Mollie is not configured
     */
    private function ensureMollieInitialized(): void
    {
        if (!$this->mollie) {
            throw new BillingExceptionClass(
                'Mollie API key is missing',
                'Mollie is not configured. Please add your Mollie API key.',
                BillingException::TYPE_STOREFRONT,
                null,
                'mollie',
                null,
                ['configured' => false]
            );
        }
    }
}
