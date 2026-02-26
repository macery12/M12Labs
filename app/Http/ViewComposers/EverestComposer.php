<?php

namespace Everest\Http\ViewComposers;

use Illuminate\View\View;
use Everest\Models\Setting;
use Everest\Services\Billing\PaymentProcessorConfigService;
use Everest\Services\Email\EmailVerificationGate;

class EverestComposer
{
    public function __construct(
        private PaymentProcessorConfigService $processorConfigService,
        private EmailVerificationGate $emailVerificationGate
    ) {
    }

    /**
     * Provide access to the asset service in the views.
     */
    public function compose(View $view): void
    {
        $processorConfig = $this->processorConfigService->getProcessorConfig();
        $view->with('everestConfiguration', [
            'auth' => [
                'registration' => [
                    'enabled' => boolval(config('modules.auth.registration.enabled', false)),
                ],
                'security' => [
                    'force2fa' => boolval(config('modules.auth.security.force2fa', false)),
                    'attempts' => config('modules.auth.security.attempts', 3),
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
                    ],
                ],
            ],
            'tickets' => [
                'enabled' => boolval(config('modules.tickets.enabled', false)),
                'maxCount' => config('modules.tickets.max_count', 3),
            ],
            'billing' => [
                'enabled' => boolval(config('modules.billing.enabled', false)),
                'processor' => config('modules.billing.processor', 'stripe'),
                'processors' => $processorConfig,
                'donations_enabled' => boolval(Setting::get('settings::modules:billing:donations_enabled', config('modules.billing.donations_enabled', true))),
                'paypal' => config('modules.billing.paypal'),
                'link' => config('modules.billing.link'),
                'keys' => [
                    'publishable' => boolval(config('modules.billing.keys.publishable')),
                    'secret' => boolval(config('modules.billing.keys.secret')),
                ],
                'mollie' => [
                    'api_key' => !empty(config('modules.billing.mollie.api_key')),
                ],
                'paypal_standalone' => [
                    'mode' => config('modules.billing.paypal_standalone.mode', 'sandbox'),
                ],
                'currency' => [
                    'symbol' => config('modules.billing.currency.symbol'),
                    'code' => config('modules.billing.currency.code'),
                ],
                'links' => [
                    'terms' => config('modules.billing.links.terms'),
                    'privacy' => config('modules.billing.links.privacy'),
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
                'integrations' => [
                    'stripe' => [
                        'enabled' => boolval(config('modules.billing.integrations.stripe.enabled', false)),
                    ],
                    'mollie' => [
                        'enabled' => boolval(config('modules.billing.integrations.mollie.enabled', false)),
                    ],
                    'paypal' => [
                        'enabled' => boolval(config('modules.billing.integrations.paypal.enabled', false)),
                    ],
                ],
            ],
            'alert' => [
                'enabled' => boolval(config('modules.alert.enabled', false)),
                'type' => config('modules.alert.type'),
                'position' => config('modules.alert.position'),
                'content' => config('modules.alert.content'),
                'uuid' => config('modules.alert.uuid'),
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
                'key' => !empty(config('modules.ai.key')),
                'user_access' => boolval(config('modules.ai.user_access', false)),
                'endpoint' => config('modules.ai.endpoint', 'https://api.openai.com/v1'),
                'model' => config('modules.ai.model', 'gpt-3.5-turbo'),
                'mode' => config('modules.ai.mode', 'openai'),
                'max_tokens' => (int) config('modules.ai.max_tokens', 200),
                'system_prompt' => config('modules.ai.system_prompt', 'You are a helpful assistant for a game server hosting panel. Provide clear, concise, and technical responses.'),
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
                'spiget_enabled' => boolval(Setting::get('settings::modules:mods:spiget_enabled', config('modules.mods.spiget_enabled', false))),
            ],
            'extensions' => [
                'enabled' => boolval(config('modules.extensions.enabled', false)),
                'available' => $this->getAvailableExtensions(),
            ],
        ]);
    }

    /**
     * Get the list of available extensions with their enabled status.
     */
    private function getAvailableExtensions(): array
    {
        $extensions = config('modules.extensions.available', []);
        
        if (!is_array($extensions)) {
            return [];
        }
        
        $availableExtensions = [];

        foreach ($extensions as $id => $extension) {
            if (!is_array($extension)) {
                continue;
            }
            $availableExtensions[$id] = [
                'name' => $extension['name'] ?? $id,
                'description' => $extension['description'] ?? '',
                'icon' => $extension['icon'] ?? 'puzzle',
                'version' => $extension['version'] ?? '1.0.0',
            ];
        }

        return $availableExtensions;
    }

    private function emailEnabled(): bool
    {
        $raw = Setting::get('settings::modules:email:resend:enabled', config('modules.email.enabled', false));

        if (is_bool($raw)) {
            return $raw;
        }

        $value = strtolower((string) $raw);

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
