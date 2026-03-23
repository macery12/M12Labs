<?php

namespace Everest\Services\Email;

class ResendPlanResolver
{
    private const PLANS = [
        'free' => [
            'key' => 'free',
            'name' => 'Free',
            'daily_limit' => 100,
            'monthly_limit' => 3000,
            'enforce_daily' => true,
            'enforce_monthly' => true,
            'allows_custom_limits' => false,
        ],
        'pro' => [
            'key' => 'pro',
            'name' => 'Pro',
            'daily_limit' => null,
            'monthly_limit' => 50000,
            'enforce_daily' => false,
            'enforce_monthly' => true,
            'allows_custom_limits' => false,
        ],
        'scale' => [
            'key' => 'scale',
            'name' => 'Scale',
            'daily_limit' => null,
            'monthly_limit' => 100000,
            'enforce_daily' => false,
            'enforce_monthly' => true,
            'allows_custom_limits' => false,
        ],
        'enterprise' => [
            'key' => 'enterprise',
            'name' => 'Enterprise',
            'daily_limit' => null,
            'monthly_limit' => null,
            'enforce_daily' => false,
            'enforce_monthly' => false,
            'allows_custom_limits' => true,
        ],
    ];

    public function __construct(private readonly EmailSettingsReader $settings)
    {
    }

    public function all(): array
    {
        return array_values(self::PLANS);
    }

    public function activePlanKey(): string
    {
        $plan = (string) $this->settings->get('settings::modules:email:resend:plan', 'free');

        return array_key_exists($plan, self::PLANS) ? $plan : 'free';
    }

    public function activePlan(): array
    {
        $planKey = $this->activePlanKey();
        $definition = $this->get($planKey);

        $customMonthly = $this->settings->get('settings::modules:email:resend:custom_monthly_limit', null);
        $customDaily = $this->settings->get('settings::modules:email:resend:custom_daily_limit', null);

        $definition['monthly_limit'] = $this->resolveLimit($definition, 'monthly_limit', $customMonthly);
        $definition['daily_limit'] = $this->resolveLimit($definition, 'daily_limit', $customDaily);
        $definition['custom_monthly_limit'] = $customMonthly !== null ? (int) $customMonthly : null;
        $definition['custom_daily_limit'] = $customDaily !== null ? (int) $customDaily : null;

        return $definition;
    }

    public function get(string $planKey): array
    {
        return self::PLANS[$planKey] ?? self::PLANS['free'];
    }

    private function resolveLimit(array $plan, string $key, mixed $customValue): ?int
    {
        if (!$plan['allows_custom_limits']) {
            return $plan[$key];
        }

        if ($customValue === null || $customValue === '') {
            return $plan[$key];
        }

        return max(0, (int) $customValue);
    }
}
