<?php

namespace Everest\Services\Billing;

use Everest\Models\Billing\Product;
use Everest\Models\Billing\BillingException;
use Everest\Exceptions\DisplayException;
use Illuminate\Support\Facades\Http;

class PayPalPaymentService
{
    private ?string $clientId = null;
    private ?string $clientSecret = null;
    private string $mode;
    private ?string $accessToken = null;

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
     * @throws DisplayException
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $this->ensurePayPalInitialized();

        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->asForm()
            ->post($this->getApiUrl() . '/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        if (!$response->successful()) {
            throw new DisplayException('Failed to authenticate with PayPal: ' . $response->body());
        }

        $this->accessToken = $response->json('access_token');
        return $this->accessToken;
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
     * @throws DisplayException
     */
    public function createOrder(Product $product, float $amount, ?int $couponId, string $returnUrl, string $cancelUrl): array
    {
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
            ->post($this->getApiUrl() . '/v2/checkout/orders', $orderData);

        if (!$response->successful()) {
            throw new DisplayException('Failed to create PayPal order: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Get a PayPal order by ID.
     *
     * @param string $orderId
     * @return array
     * @throws DisplayException
     */
    public function getOrder(string $orderId): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->get($this->getApiUrl() . '/v2/checkout/orders/' . $orderId);

        if (!$response->successful()) {
            throw new DisplayException('Failed to fetch PayPal order: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Capture payment for an order.
     *
     * @param string $orderId
     * @return array
     * @throws DisplayException
     */
    public function captureOrder(string $orderId): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->post($this->getApiUrl() . '/v2/checkout/orders/' . $orderId . '/capture');

        if (!$response->successful()) {
            throw new DisplayException('Failed to capture PayPal order: ' . $response->body());
        }

        return $response->json();
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
     * @throws DisplayException if PayPal is not configured
     */
    private function ensurePayPalInitialized(): void
    {
        if (!$this->clientId || !$this->clientSecret) {
            BillingException::create([
                'exception_type' => BillingException::TYPE_STOREFRONT,
                'title' => 'PayPal credentials are missing',
                'description' => 'Add the PayPal Client ID and Client Secret to your billing configuration',
            ]);
            throw new DisplayException('PayPal is not configured. Please add your PayPal credentials.');
        }
    }
}
