<?php

namespace Everest\Services\Billing;

/**
 * Service to manage payment processor configuration and availability.
 */
class PaymentProcessorConfigService
{
    /**
     * Check if Stripe is properly configured and enabled.
     *
     * @return bool
     */
    public function isStripeAvailable(): bool
    {
        // Check if Stripe is enabled in config
        $stripeEnabled = config('modules.billing.processors.stripe.enabled', false);
        
        // Check if required Stripe keys are configured
        $publishableKey = config('modules.billing.keys.publishable', '');
        $secretKey = config('modules.billing.keys.secret', '');
        
        return $stripeEnabled && !empty($publishableKey) && !empty($secretKey);
    }

    /**
     * Check if Mollie is properly configured and enabled.
     *
     * @return bool
     */
    public function isMollieAvailable(): bool
    {
        // Check if Mollie is enabled in config
        $mollieEnabled = config('modules.billing.processors.mollie.enabled', false);
        
        // Check if Mollie API key is configured
        $apiKey = config('modules.billing.mollie.api_key', '');
        
        return $mollieEnabled && !empty($apiKey);
    }

    /**
     * Get all available payment processors.
     *
     * @return array Array of available processor names ['stripe', 'mollie']
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
        
        return $processors;
    }

    /**
     * Check if at least one payment processor is available.
     *
     * @return bool
     */
    public function hasAnyProcessor(): bool
    {
        return $this->isStripeAvailable() || $this->isMollieAvailable();
    }

    /**
     * Get payment processor configuration for frontend.
     *
     * @return array
     */
    public function getProcessorConfig(): array
    {
        return [
            'stripe' => [
                'available' => $this->isStripeAvailable(),
                'enabled' => config('modules.billing.processors.stripe.enabled', false),
            ],
            'mollie' => [
                'available' => $this->isMollieAvailable(),
                'enabled' => config('modules.billing.processors.mollie.enabled', false),
            ],
        ];
    }
}
