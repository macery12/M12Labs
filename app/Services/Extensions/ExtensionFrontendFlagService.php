<?php

namespace Everest\Services\Extensions;

use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionSecret;
use Everest\Services\Extensions\Manifest\Definitions\SettingDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PackageFlagPredicate;
use Everest\Services\Extensions\Manifest\Definitions\PackageFlagDefinition;

/**
 * Resolve the authenticated frontend state declared by installed packages.
 *
 * Package code never runs here. Inputs are the verified runtime projection,
 * the package's own typed settings, and whether one of its declared encrypted
 * secrets exists. The output is booleans only and is safe to publish; secret
 * values are never selected from the database.
 */
class ExtensionFrontendFlagService
{
    public function __construct(private ExtensionRuntimePlanService $runtimePlan)
    {
    }

    /**
     * @return array{
     *   active: array<int, string>,
     *   flags: array<string, array<string, bool>>
     * }
     */
    public function snapshot(): array
    {
        $plan = $this->runtimePlan->plan();
        $active = array_keys($plan);
        $resolved = [];
        foreach ($plan as $id => $entry) {
            $resolved[$id] = array_fill_keys(
                array_map(fn (PackageFlagDefinition $flag): string => $flag->name, $entry->capabilities->flags),
                false,
            );
        }

        $withFlags = array_filter(
            $plan,
            fn (ExtensionRuntimeEntry $entry): bool => $entry->capabilities->flags !== [],
        );
        if ($withFlags === []) {
            return ['active' => $active, 'flags' => $resolved];
        }

        try {
            $ids = array_keys($withFlags);
            $configs = ExtensionConfig::query()
                ->whereIn('extension_id', $ids)
                ->get(['extension_id', 'settings'])
                ->keyBy('extension_id');

            // Presence is the entire secret predicate contract. Do not select
            // ciphertext, context hashes, or any other credential material.
            $secretRows = ExtensionSecret::query()
                ->whereIn('extension_id', $ids)
                ->get(['extension_id', 'key']);
            $configuredSecrets = [];
            foreach ($secretRows as $secret) {
                $configuredSecrets[$secret->extension_id][$secret->key] = true;
            }

            foreach ($withFlags as $id => $entry) {
                $values = $configs->get($id)?->settings;
                $settings = is_array($values) ? $values : [];
                $secrets = $configuredSecrets[$id] ?? [];

                foreach ($entry->capabilities->flags as $flag) {
                    $resolved[$id][$flag->name] = $this->evaluate(
                        $flag,
                        $entry->capabilities->settings,
                        $settings,
                        $secrets,
                    );
                }
            }
        } catch (\Throwable) {
            // Fresh installs can render before the extension tables exist.
            // The runtime plan already fails closed; keep every declared flag
            // false rather than making the whole panel view fail to compose.
        }

        return ['active' => $active, 'flags' => $resolved];
    }

    /**
     * @param array<int, SettingDefinition> $definitions
     * @param array<string, mixed> $settings
     * @param array<string, true> $secrets
     */
    private function evaluate(PackageFlagDefinition $flag, array $definitions, array $settings, array $secrets): bool
    {
        $definitionMap = [];
        foreach ($definitions as $definition) {
            $definitionMap[$definition->key] = $definition;
        }

        foreach ($flag->all as $predicate) {
            if (!$this->predicate($predicate, $definitionMap, $settings, $secrets)) {
                return false;
            }
        }

        if ($flag->any === []) {
            return true;
        }

        foreach ($flag->any as $predicate) {
            if ($this->predicate($predicate, $definitionMap, $settings, $secrets)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, SettingDefinition> $definitions
     * @param array<string, mixed> $settings
     * @param array<string, true> $secrets
     */
    private function predicate(
        PackageFlagPredicate $predicate,
        array $definitions,
        array $settings,
        array $secrets,
    ): bool {
        if ($predicate->source === PackageFlagPredicate::SOURCE_SECRET) {
            $configured = isset($secrets[$predicate->key]);

            return $configured === $predicate->expected;
        }

        $definition = $definitions[$predicate->key] ?? null;
        if ($definition === null) {
            return false;
        }

        $present = array_key_exists($predicate->key, $settings);
        $value = $present ? $settings[$predicate->key] : $definition->default;
        $configured = $present || $definition->default !== null;

        if ($predicate->operator === PackageFlagPredicate::OPERATOR_CONFIGURED) {
            // Empty strings are the one configured value that behaves as
            // missing. False and zero remain legitimate typed configuration.
            $configured = $configured && $value !== null && $value !== '';

            return $configured === $predicate->expected;
        }

        return match ($definition->type) {
            'boolean' => (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? null) === $predicate->expected,
            'number' => is_numeric($value) && (float) $value === (float) $predicate->expected,
            default => is_string($value) && $value === $predicate->expected,
        };
    }
}
