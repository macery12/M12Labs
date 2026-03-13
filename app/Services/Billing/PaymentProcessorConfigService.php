<?php

namespace Everest\Services\Billing;

use Everest\Models\Setting;

/**
 * Service to manage payment processor configuration and availability.
 */
class PaymentProcessorConfigService
{
    /**
     * Check if Stripe is properly configured and enabled.
     */
    public function isStripeAvailable(): bool
    {
        // Check if Stripe integration is enabled (this reads from database settings)
        // Falls back to checking legacy processor config for backward compatibility
        $stripeEnabled = config(
            'modules.billing.integrations.stripe.enabled',
            config('modules.billing.processor') === 'stripe'
        );

        // Check if required Stripe keys are configured
        $publishableKey = Setting::get('settings::modules:billing:keys:publishable', config('modules.billing.keys.publishable', ''));
        $secretKey = Setting::get('settings::modules:billing:keys:secret', config('modules.billing.keys.secret', ''));

        return $stripeEnabled && !empty($publishableKey) && !empty($secretKey);
    }

    /**
     * Check if Mollie is properly configured and enabled.
     */
    public function isMollieAvailable(): bool
    {
        // Check if Mollie integration is enabled (this reads from database settings)
        // Falls back to checking legacy processor config for backward compatibility
        $mollieEnabled = config(
            'modules.billing.integrations.mollie.enabled',
            config('modules.billing.processor') === 'mollie'
        );

        // Check if Mollie API key is configured
        $apiKey = Setting::get('settings::modules:billing:mollie:api_key', config('modules.billing.mollie.api_key', ''));

        return $mollieEnabled && !empty($apiKey);
    }

    /**
     * Check if PayPal standalone is properly configured and enabled.
     */
    public function isPayPalAvailable(): bool
    {
        // Check if PayPal integration is enabled
        $paypalEnabled = config('modules.billing.integrations.paypal.enabled', false);

        // Check if PayPal credentials are configured
        $clientId = Setting::get('settings::modules:billing:paypal_standalone:client_id', config('modules.billing.paypal_standalone.client_id', ''));
        $clientSecret = Setting::get('settings::modules:billing:paypal_standalone:client_secret', config('modules.billing.paypal_standalone.client_secret', ''));

        return $paypalEnabled && !empty($clientId) && !empty($clientSecret);
    }

    /**
     * Get all available payment processors.
     *
     * @return array Array of available processor names ['stripe', 'mollie', 'paypal']
     */
    public function getAvailableProcessors(): array
    {
        $processors = [];

        if ($this->isStripeAvailable()) {
            $processors[] = 'stripe';
        }

        if ($this->isMollieAvailable()) {
            $processors[] = 'mollie';
        }

        if ($this->isPayPalAvailable()) {
            $processors[] = 'paypal';
        }

        return $processors;
    }

    /**
     * Check if at least one payment processor is available.
     */
    public function hasAnyProcessor(): bool
    {
        return $this->isStripeAvailable() || $this->isMollieAvailable() || $this->isPayPalAvailable();
    }

    /**
     * Get payment processor configuration for frontend.
     */
    public function getProcessorConfig(): array
    {
        return [
            'stripe' => [
                'available' => $this->isStripeAvailable(),
                'enabled' => config(
                    'modules.billing.integrations.stripe.enabled',
                    config('modules.billing.processor') === 'stripe'
                ),
            ],
            'mollie' => [
                'available' => $this->isMollieAvailable(),
                'enabled' => config(
                    'modules.billing.integrations.mollie.enabled',
                    config('modules.billing.processor') === 'mollie'
                ),
            ],
            'paypal' => [
                'available' => $this->isPayPalAvailable(),
                'enabled' => config('modules.billing.integrations.paypal.enabled', false),
            ],
        ];
    }
}
