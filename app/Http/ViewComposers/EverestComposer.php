<?php

namespace Everest\Http\ViewComposers;

use Illuminate\View\View;
use Everest\Models\Setting;
use Everest\Services\Billing\InvoiceSettingsService;
use Everest\Services\Billing\PaymentProcessorConfigService;
use Everest\Services\Email\EmailVerificationGate;
use Everest\Services\Email\EmailManager;

class EverestComposer
{
    public function __construct(
        private PaymentProcessorConfigService $processorConfigService,
        private EmailVerificationGate $emailVerificationGate,
        private InvoiceSettingsService $invoiceSettingsService
    ) {
    }

    /**
     * Provide access to the asset service in the views.
     */
    public function compose(View $view): void
    {
        $processorConfig = $this->processorConfigService->getProcessorConfig();
        
        // Build public configuration (slim, essential fields only)
        $configuration = [
            'auth' => [
                'registration' => [
                    'enabled' => boolval(config('modules.auth.registration.enabled', false)),
                ],
                'security' => [
                    'force2fa' => boolval(config('modules.auth.security.force2fa', false)),
                ],
                'captcha' => [
                    'provider' => Setting::get('settings::modules:auth:captcha:provider', 'disabled'),
                    'site_key' => Setting::get('settings::modules:auth:captcha:site_key', ''),
                ],
                'modules' => [
                    'discord' => [
                        'enabled' => boolval(config('modules.auth.discord.enabled', false)),
                        'clientId' => !empty(config('modules.auth.discord.client_id')),
                        'clientSecret' => !empty(config('modules.auth.discord.client_secret')),
                    ],
                    'google' => [
                        'enabled' => boolval(config('modules.auth.google.enabled', false)),
                        'clientId' => !empty(config('modules.auth.google.client_id', false)),
                        'clientSecret' => !empty(config('modules.auth.google.client_secret')),
                    ],
                    'onboarding' => [
                        'enabled' => boolval(config('modules.auth.onboarding.enabled', false)),
                        'content' => config('modules.auth.onboarding.content', ''),
                    ],
                    'jguard' => [
                        'enabled' => boolval(config('modules.auth.jguard.enabled', false)),
                        'approval_mode' => config('modules.auth.jguard.approval_mode', 'manual'),
                        'delay' => (int) config('modules.auth.jguard.delay', 60),
                        'pending_message' => Setting::get('settings::modules:auth:jguard:pending_message', config('modules.auth.jguard.pending_message', '')),
                    ],
                ],
            ],
            'tickets' => [
                'enabled' => boolval(config('modules.tickets.enabled', false)),
                'maxCount' => config('modules.tickets.max_count', 3),
            ],
            'billing' => [
                'enabled' => boolval(config('modules.billing.enabled', false)),
                'processors' => $processorConfig,
                'currency' => [
                    'symbol' => config('modules.billing.currency.symbol'),
                    'code' => config('modules.billing.currency.code'),
                ],
                'links' => [
                    'terms' => config('modules.billing.links.terms'),
                    'privacy' => config('modules.billing.links.privacy'),
                ],
                'integrations' => [
                    'stripe' => [
                        'enabled' => boolval(config('modules.billing.integrations.stripe.enabled', false)),
                    ],
                    'paypal' => [
                        'enabled' => boolval(config('modules.billing.integrations.paypal.enabled', false)),
                    ],
                ],
            ],
            'email' => [
                'enabled' => $this->emailEnabled(),
                'resend' => [
                    'enabled' => $this->emailEnabled(),
                ],
                'verification_rules' => $this->emailVerificationGate->getRules(),
            ],
            'ai' => [
                'enabled' => boolval(config('modules.ai.enabled', false)),
                'feature_server_assistant' => boolval(config('modules.ai.feature_server_assistant', true)),
                'feature_crash_analysis' => boolval(config('modules.ai.feature_crash_analysis', true)),
            ],
            'webhooks' => [
                'enabled' => boolval(config('modules.webhooks.enabled', false)),
                'url' => !empty(config('modules.webhooks.url')),
            ],
            'mods' => [
                'enabled' => boolval(Setting::get('settings::modules:mods:enabled', config('modules.mods.enabled', false))),
                'curseforge_api_key' => !empty(Setting::get('settings::modules:mods:curseforge_api_key', config('modules.mods.curseforge_api_key'))),
                'default_source' => Setting::get('settings::modules:mods:default_source', config('modules.mods.default_source', 'modrinth')),
                'rate_limit' => [
                    'requests_per_minute' => config('modules.mods.rate_limit.requests_per_minute', 30),
                    'requests_per_hour' => config('modules.mods.rate_limit.requests_per_hour', 1800),
                ],
            ],
            'extensions' => [
                'enabled' => boolval(config('modules.extensions.enabled', false)),
            ],
            'custom_domains' => [
                'enabled' => boolval(config('modules.custom_domains.enabled', false)),
            ],
        ];
        
        // Merge admin-only configuration if user is authenticated admin
        $user = auth()->user();
        if ($user && ($user->root_admin || $user->admin_role_id)) {
            $configuration = array_merge_recursive($configuration, $this->getAdminConfiguration());
        }
        
        $view->with('everestConfiguration', $configuration);
    }
    
    /**
     * Get admin-only configuration with sensitive/admin-specific fields.
     * This is only exposed to admin users.
     */
    private function getAdminConfiguration(): array
    {
        $invoiceSettings = $this->invoiceSettingsService->get();

        return [
            'billing' => [
                'keys' => [
                    'publishable' => boolval(config('modules.billing.keys.publishable')),
                    'secret' => boolval(config('modules.billing.keys.secret')),
                ],
                'paypal_standalone' => [
                    'mode' => config('modules.billing.paypal_standalone.mode', 'sandbox'),
                ],
                'renewal' => [
                    'days' => config('modules.billing.renewal.days', 30),
                    'free_renewal_days' => config('modules.billing.renewal.free_renewal_days', 30),
                    'suspension_threshold' => config('modules.billing.renewal.suspension_threshold', 7),
                    'suspension_threshold_percentage' => config('modules.billing.renewal.suspension_threshold_percentage', 0.20),
                    'min_suspension_threshold_days' => config('modules.billing.renewal.min_suspension_threshold_days', 3),
                    'max_suspension_threshold_days' => config('modules.billing.renewal.max_suspension_threshold_days', 7),
                    'free_suspension_days' => config('modules.billing.renewal.free_suspension_days', 7),
                    'paid_suspension_days' => config('modules.billing.renewal.paid_suspension_days', 30),
                    'default_billing_days' => (int) Setting::get('settings::modules:billing:renewal:default_billing_days', config('modules.billing.renewal.default_billing_days', 30)),
                    'multiplier_steps' => Setting::get('settings::modules:billing:renewal:multiplier_steps', config('modules.billing.renewal.multiplier_steps')),
                ],
                'plan_change_cooldown_hours' => config('modules.billing.plan_change_cooldown_hours', 72),
                'require_billing_address' => (bool) $invoiceSettings->require_billing_address,
            ],
        ];
    }

    private function emailEnabled(): bool
    {
        return EmailManager::isDeliveryEnabled();
    }
}
