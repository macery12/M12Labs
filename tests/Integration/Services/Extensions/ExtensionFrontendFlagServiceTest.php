<?php

namespace Everest\Tests\Integration\Services\Extensions;

use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionSecret;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Services\Extensions\ExtensionRuntimeEntry;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\ExtensionFrontendFlagService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\Definitions\SecretDefinition;
use Everest\Services\Extensions\Manifest\Definitions\SettingDefinition;
use Everest\Services\Extensions\Manifest\Definitions\PackageFlagPredicate;
use Everest\Services\Extensions\Manifest\Definitions\PackageFlagDefinition;

class ExtensionFrontendFlagServiceTest extends IntegrationTestCase
{
    public function testItEvaluatesSettingsAndSecretPresenceWithoutPublishingValues(): void
    {
        ExtensionConfig::query()->create([
            'extension_id' => 'assistant',
            'enabled' => true,
            'settings' => [
                'agent_enabled' => true,
                'tools_verified' => true,
                'provider' => 'openai',
            ],
        ]);
        ExtensionSecret::query()->create([
            'extension_id' => 'assistant',
            'key' => 'api_key',
            'value' => 'ciphertext-that-must-not-be-selected',
            'key_version' => 1,
            'context_hash' => str_repeat('a', 64),
        ]);

        $settings = [
            new SettingDefinition('agent_enabled', 'boolean', 'ext.assistant.agent', default: false),
            new SettingDefinition('tools_verified', 'boolean', 'ext.assistant.tools', default: false),
            new SettingDefinition('provider', 'select', 'ext.assistant.provider', enum: ['ollama', 'openai']),
        ];
        $flag = new PackageFlagDefinition(
            'agent-ready',
            all: [
                new PackageFlagPredicate('setting', 'agent_enabled', 'equals', true),
                new PackageFlagPredicate('setting', 'tools_verified', 'equals', true),
            ],
            any: [
                new PackageFlagPredicate('setting', 'provider', 'equals', 'ollama'),
                new PackageFlagPredicate('secret', 'api_key', 'configured', true),
            ],
        );
        $capabilities = new ExtensionCapabilitySet(
            secrets: [new SecretDefinition('api_key', 'ext.assistant.api_key')],
            settings: $settings,
            flags: [$flag],
        );

        $plan = $this->createMock(ExtensionRuntimePlanService::class);
        $plan->method('plan')->willReturn([
            'assistant' => new ExtensionRuntimeEntry('assistant', '1.0.0', $capabilities),
            'plain' => new ExtensionRuntimeEntry('plain', '1.0.0', new ExtensionCapabilitySet()),
        ]);

        $snapshot = (new ExtensionFrontendFlagService($plan))->snapshot();

        $this->assertSame(['assistant', 'plain'], $snapshot['active']);
        $this->assertTrue($snapshot['flags']['assistant']['agent-ready']);
        $this->assertSame([], $snapshot['flags']['plain']);
        $this->assertStringNotContainsString('ciphertext', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function testMissingInputsFailClosedAndConfiguredFalseCanBeDeclared(): void
    {
        ExtensionConfig::query()->create([
            'extension_id' => 'assistant_missing',
            'enabled' => true,
            'settings' => [],
        ]);

        $capabilities = new ExtensionCapabilitySet(
            secrets: [new SecretDefinition('api_key', 'ext.assistant.api_key')],
            settings: [new SettingDefinition('agent_enabled', 'boolean', 'ext.assistant.agent', default: false)],
            flags: [
                new PackageFlagDefinition('ready', all: [
                    new PackageFlagPredicate('setting', 'agent_enabled', 'equals', true),
                ]),
                new PackageFlagDefinition('needs-key', all: [
                    new PackageFlagPredicate('secret', 'api_key', 'configured', false),
                ]),
            ],
        );
        $plan = $this->createMock(ExtensionRuntimePlanService::class);
        $plan->method('plan')->willReturn([
            'assistant_missing' => new ExtensionRuntimeEntry('assistant_missing', '1.0.0', $capabilities),
        ]);

        $flags = (new ExtensionFrontendFlagService($plan))->snapshot()['flags']['assistant_missing'];

        $this->assertFalse($flags['ready']);
        $this->assertTrue($flags['needs-key']);
    }
}
