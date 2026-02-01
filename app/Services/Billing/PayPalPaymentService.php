<?php

namespace Everest\Services\Billing;

use Everest\Models\Billing\Product;
use Everest\Models\Billing\BillingException;
use Everest\Exceptions\Billing\BillingException as BillingExceptionClass;
use Everest\Exceptions\DisplayException;
use Illuminate\Support\Facades\Http;

class PayPalPaymentService
{
    private ?string $clientId = null;
    private ?string $clientSecret = null;
    private string $mode;
    private ?string $accessToken = null;
    private ?int $tokenExpiresAt = null;

    public function __construct()
    {
        $config = config('modules.billing.paypal_standalone');
        $this->clientId = $config['client_id'] ?? null;
        $this->clientSecret = $config['client_secret'] ?? null;
        $this->mode = $config['mode'] ?? 'sandbox';
    }

    /**
     * Get the PayPal API base URL based on mode.
     */
    private function getApiUrl(): string
    {
        return $this->mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Get OAuth2 access token from PayPal.
     *
     * @throws BillingExceptionClass
     */
    private function getAccessToken(): string
    {
        // Check if we have a valid cached token
        if ($this->accessToken && $this->tokenExpiresAt && time() < $this->tokenExpiresAt) {
            return $this->accessToken;
        }

        $this->ensurePayPalInitialized();

        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post($this->getApiUrl() . '/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);

            if (!$response->successful()) {
                \Log::error('PayPal authentication failed', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);
                
                throw new BillingExceptionClass(
                    'PayPal authentication failed',
                    'Failed to authenticate with PayPal. Please check your credentials.',
                    BillingException::TYPE_PAYMENT,
                    null,
                    'paypal',
                    null,
                    ['status' => $response->status(), 'response' => $response->json()]
                );
            }

            $data = $response->json();
            $this->accessToken = $data['access_token'];
            // Set expiration to 90% of the actual expiration time to be safe
            $expiresIn = $data['expires_in'] ?? 32400; // Default to 9 hours if not provided
            $this->tokenExpiresAt = time() + (int)($expiresIn * 0.9);
            
            return $this->accessToken;
        } catch (BillingExceptionClass $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('PayPal authentication exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw new BillingExceptionClass(
                'PayPal authentication error',
                'An unexpected error occurred while authenticating with PayPal: ' . $e->getMessage(),
                BillingException::TYPE_PAYMENT,
                null,
                'paypal',
                null,
                ['error' => $e->getMessage()],
                $e
            );
        }
    }

    /**
     * Create a PayPal order.
     *
     * @param Product $product
     * @param float $amount
     * @param int|null $couponId
     * @param string $returnUrl
     * @param string $cancelUrl
     * @return array PayPal order data
     * @throws BillingExceptionClass
     */
    public function createOrder(Product $product, float $amount, ?int $couponId, string $returnUrl, string $cancelUrl): array
    {
        try {
            $token = $this->getAccessToken();

            $orderData = [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => 'product_' . $product->id,
                        'description' => $product->name,
                        'amount' => [
                            'currency_code' => strtoupper(config('modules.billing.currency.code')),
                            'value' => number_format($amount, 2, '.', ''),
                        ],
                        'custom_id' => json_encode([
                            'product_id' => $product->id,
                            'coupon_id' => $couponId,
                        ]),
                    ],
                ],
                'application_context' => [
                    'brand_name' => config('app.name'),
                    'landing_page' => 'BILLING',
                    'user_action' => 'PAY_NOW',
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                ],
            ];

            $response = Http::withToken($token)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($this->getApiUrl() . '/v2/checkout/orders', $orderData);

            if (!$response->successful()) {
                \Log::error('PayPal order creation failed', [
                    'status' => $response->status(),
                    'error_response' => $response->json(),
                    'raw_body' => $response->body(),
                ]);
                
                throw new BillingExceptionClass(
                    'PayPal order creation failed',
                    'Failed to create PayPal order. Please try again or contact support.',
                    BillingException::TYPE_PAYMENT,
                    null,
                    'paypal',
                    null,
                    [
                        'product_id' => $product->id,
                        'amount' => $amount,
                        'status' => $response->status(),
                        'response' => $response->json(),
                    ]
                );
            }

            return $response->json();
        } catch (BillingExceptionClass $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('PayPal order creation exception', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw new BillingExceptionClass(
                'PayPal order creation error',
                'An unexpected error occurred while creating PayPal order: ' . $e->getMessage(),
                BillingException::TYPE_PAYMENT,
                null,
                'paypal',
                null,
                ['product_id' => $product->id, 'error' => $e->getMessage()],
                $e
            );
        }
    }

    /**
     * Get a PayPal order by ID.
     *
     * @param string $orderId
     * @return array
     * @throws BillingExceptionClass
     */
    public function getOrder(string $orderId): array
    {
        try {
            // Validate order ID format (PayPal order IDs are alphanumeric with hyphens)
            if (!preg_match('/^[A-Z0-9-]+$/i', $orderId)) {
                throw new BillingExceptionClass(
                    'Invalid PayPal order ID format',
                    'Invalid PayPal order ID format.',
                    BillingException::TYPE_VALIDATION,
                    null,
                    'paypal',
                    $orderId,
                    ['order_id' => $orderId]
                );
            }

            $token = $this->getAccessToken();

            $response = Http::withToken($token)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->get($this->getApiUrl() . '/v2/checkout/orders/' . $orderId);

            if (!$response->successful()) {
                \Log::error('PayPal order fetch failed', [
                    'order_id' => $orderId,
                    'status' => $response->status(),
                    'error_response' => $response->json(),
                    'raw_body' => $response->body(),
                ]);
                
                throw new BillingExceptionClass(
                    'PayPal order fetch failed',
                    'Failed to fetch PayPal order. Please try again or contact support.',
                    BillingException::TYPE_PAYMENT,
                    null,
                    'paypal',
                    $orderId,
                    ['status' => $response->status(), 'response' => $response->json()]
                );
            }

            return $response->json();
        } catch (BillingExceptionClass $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('PayPal order fetch exception', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            
            throw new BillingExceptionClass(
                'PayPal order fetch error',
                'An unexpected error occurred while fetching PayPal order: ' . $e->getMessage(),
                BillingException::TYPE_PAYMENT,
                null,
                'paypal',
                $orderId,
                ['error' => $e->getMessage()],
                $e
            );
        }
    }

    /**
     * Capture payment for an order.
     *
     * @param string $orderId
     * @return array
     * @throws BillingExceptionClass
     */
    public function captureOrder(string $orderId): array
    {
        try {
            $token = $this->getAccessToken();

            $response = Http::withToken($token)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Prefer' => 'return=representation',
                ])
                ->post($this->getApiUrl() . '/v2/checkout/orders/' . $orderId . '/capture', new \stdClass());


            if (!$response->successful()) {
                \Log::error('PayPal order capture failed', [
                    'order_id' => $orderId,
                    'status' => $response->status(),
                    'error_response' => $response->json(),
                    'raw_body' => $response->body(),
                ]);
                
                throw new BillingExceptionClass(
                    'PayPal order capture failed',
                    'Failed to capture PayPal order. Please try again or contact support.',
                    BillingException::TYPE_PAYMENT,
                    null,
                    'paypal',
                    $orderId,
                    ['status' => $response->status(), 'response' => $response->json()]
                );
            }

            return $response->json();
        } catch (BillingExceptionClass $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('PayPal order capture exception', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            
            throw new BillingExceptionClass(
                'PayPal order capture error',
                'An unexpected error occurred while capturing PayPal order: ' . $e->getMessage(),
                BillingException::TYPE_PAYMENT,
                null,
                'paypal',
                $orderId,
                ['error' => $e->getMessage()],
                $e
            );
        }
    }

    /**
     * Check if a PayPal order is approved (ready for capture).
     *
     * @param string $orderId
     * @return bool
     */
    public function isOrderApproved(string $orderId): bool
    {
        $order = $this->getOrder($orderId);
        return ($order['status'] ?? '') === 'APPROVED';
    }

    /**
     * Check if a PayPal order has been completed (captured).
     *
     * @param string $orderId
     * @return bool
     */
    public function isOrderCompleted(string $orderId): bool
    {
        $order = $this->getOrder($orderId);
        return ($order['status'] ?? '') === 'COMPLETED';
    }

    /**
     * Get the approval URL for customer to complete payment.
     *
     * @param array $order
     * @return string|null
     */
    public function getApprovalUrl(array $order): ?string
    {
        $links = $order['links'] ?? [];
        foreach ($links as $link) {
            if ($link['rel'] === 'approve') {
                return $link['href'];
            }
        }
        return null;
    }

    /**
     * Get the order status.
     *
     * @param string $orderId
     * @return string
     */
    public function getOrderStatus(string $orderId): string
    {
        $order = $this->getOrder($orderId);
        return $order['status'] ?? 'UNKNOWN';
    }

    /**
     * Extract custom data from PayPal order.
     *
     * @param array $order
     * @return array
     */
    public function getCustomData(array $order): array
    {
        $customId = $order['purchase_units'][0]['custom_id'] ?? null;
        if ($customId) {
            return json_decode($customId, true) ?? [];
        }
        return [];
    }

    /**
     * Ensure PayPal client is initialized.
     *
     * @throws BillingExceptionClass if PayPal is not configured
     */
    private function ensurePayPalInitialized(): void
    {
        if (!$this->clientId || !$this->clientSecret) {
            throw new BillingExceptionClass(
                'PayPal credentials are missing',
                'PayPal is not configured. Please add your PayPal credentials.',
                BillingException::TYPE_STOREFRONT,
                null,
                'paypal',
                null,
                ['configured' => false]
            );
        }
    }
}
