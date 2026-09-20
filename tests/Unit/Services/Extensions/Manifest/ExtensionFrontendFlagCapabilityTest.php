<?php

namespace Everest\Tests\Unit\Services\Extensions\Manifest;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityDiff;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;

/** `capabilities.flags` and the page/slot gates that consume them. */
class ExtensionFrontendFlagCapabilityTest extends TestCase
{
    private ExtensionManifestParser $parser;

    public function setUp(): void
    {
        parent::setUp();

        $this->parser = new ExtensionManifestParser();
    }

    /** @return array<string, mixed> */
    private function manifest(array $capabilities): array
    {
        return [
            'manifestVersion' => 3,
            'package' => ['id' => 'demo', 'version' => '1.0.0'],
            'extension' => ['id' => 'demo', 'name' => 'Demo', 'icon' => 'puzzle'],
            'compatiblePanelVersions' => ['>=Alpha 4.0 <Alpha 5.0'],
            'capabilities' => $capabilities,
            'files' => [['path' => 'app/Extensions/Packages/demo/README.md', 'sha256' => str_repeat('a', 64)]],
        ];
    }

    /** @return array<string, mixed> */
    private function declaredCapabilities(): array
    {
        return [
            'settings' => ['fields' => [
                ['key' => 'agent_enabled', 'type' => 'boolean', 'labelKey' => 'ext.demo.agent', 'default' => false],
                ['key' => 'tools_verified', 'type' => 'boolean', 'labelKey' => 'ext.demo.tools', 'default' => false],
                ['key' => 'provider', 'type' => 'select', 'labelKey' => 'ext.demo.provider', 'enum' => ['ollama', 'openai']],
            ]],
            'secrets' => [
                ['key' => 'api_key', 'labelKey' => 'ext.demo.api_key'],
            ],
            'flags' => [[
                'name' => 'agent-ready',
                'all' => [
                    ['setting' => 'agent_enabled', 'equals' => true],
                    ['setting' => 'tools_verified', 'equals' => true],
                ],
                'any' => [
                    ['setting' => 'provider', 'equals' => 'ollama'],
                    ['secret' => 'api_key', 'configured' => true],
                ],
            ]],
            'pages' => [
                'server' => [[
                    'slug' => 'assistant',
                    'labelKey' => 'ext.demo.nav.assistant',
                    'icon' => 'bot',
                    'category' => 'general',
                    'requiredFlags' => ['agent-ready'],
                ]],
            ],
            'slots' => [[
                'name' => 'server-layout.overlay',
                'entry' => 'assistant',
                'requiredFlags' => ['agent-ready'],
            ]],
        ];
    }

    public function testParsesPanelEvaluatedFlagsAndTheirConsumers(): void
    {
        $capabilities = $this->parser->parse($this->manifest($this->declaredCapabilities()))->capabilities;

        $this->assertCount(1, $capabilities->flags);
        $this->assertSame('agent-ready', $capabilities->flags[0]->name);
        $this->assertCount(2, $capabilities->flags[0]->all);
        $this->assertCount(2, $capabilities->flags[0]->any);
        $this->assertSame(['agent-ready'], $capabilities->serverPages[0]->requiredFlags);
        $this->assertSame(['agent-ready'], $capabilities->slots[0]->requiredFlags);
        $this->assertSame(1, $capabilities->summary()['flags']);
    }

    public function testStoredProjectionHydratesWithoutChangingItsHash(): void
    {
        $capabilities = $this->parser->parse($this->manifest($this->declaredCapabilities()))->capabilities;
        $hydrated = app(ExtensionRuntimePlanService::class)->hydrateCapabilities($capabilities->jsonSerialize());

        $this->assertNotNull($hydrated);
        $this->assertSame($capabilities->hash(), $hydrated->hash());
        $this->assertSame(['agent-ready'], $hydrated->serverPages[0]->requiredFlags);
    }

    public function testRejectsAFlagThatReadsAnUndeclaredInput(): void
    {
        $capabilities = $this->declaredCapabilities();
        $capabilities['flags'][0]['all'][0] = ['setting' => 'hidden_switch', 'equals' => true];

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not declare as a readable setting');

        $this->parser->parse($this->manifest($capabilities));
    }

    public function testRejectsReadingASecretValueInsteadOfPresence(): void
    {
        $capabilities = $this->declaredCapabilities();
        $capabilities['flags'][0]['any'][1] = ['secret' => 'api_key', 'equals' => 'plaintext'];

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('secret values are never available to flags');

        $this->parser->parse($this->manifest($capabilities));
    }

    public function testRejectsASettingComparisonWithTheWrongType(): void
    {
        $capabilities = $this->declaredCapabilities();
        $capabilities['flags'][0]['all'][0] = ['setting' => 'agent_enabled', 'equals' => 'true'];

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('must match the declared boolean setting type');

        $this->parser->parse($this->manifest($capabilities));
    }

    public function testRejectsAPageRequiringAnUndeclaredFlag(): void
    {
        $capabilities = $this->declaredCapabilities();
        $capabilities['pages']['server'][0]['requiredFlags'] = ['not-declared'];

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('which the manifest does not declare');

        $this->parser->parse($this->manifest($capabilities));
    }

    public function testChangingAFlagExpressionRequiresFreshCapabilityApproval(): void
    {
        $before = $this->parser->parse($this->manifest($this->declaredCapabilities()))->capabilities;
        $changed = $this->declaredCapabilities();
        $changed['flags'][0]['all'][0]['equals'] = false;
        $after = $this->parser->parse($this->manifest($changed))->capabilities;
        $diff = ExtensionCapabilityDiff::between($before, $after);

        $this->assertTrue($diff->isEscalation());
        $this->assertNotEmpty(array_filter($diff->escalations, fn (string $item): bool => str_starts_with($item, 'flag:')));
    }

    public function testEmptyCapabilityProjectionStaysBackwardCompatible(): void
    {
        $this->assertArrayNotHasKey('flags', (new ExtensionCapabilitySet())->jsonSerialize());
    }
}
