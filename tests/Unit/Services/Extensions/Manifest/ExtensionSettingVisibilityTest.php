<?php

namespace Everest\Tests\Unit\Services\Extensions\Manifest;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;

/** `visibleWhen` on settings and secrets, and the `internal` visibility. */
class ExtensionSettingVisibilityTest extends TestCase
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
    private function capabilities(): array
    {
        return [
            'settings' => ['fields' => [
                // Declared before the field it depends on, which is the case
                // a single-pass parser would get wrong.
                [
                    'key' => 'keep_alive', 'type' => 'text', 'labelKey' => 'ext.demo.keepAlive',
                    'visibleWhen' => ['any' => [
                        ['setting' => 'provider', 'equals' => 'ollama'],
                        ['setting' => 'provider', 'equals' => 'compatible'],
                    ]],
                ],
                ['key' => 'provider', 'type' => 'select', 'labelKey' => 'ext.demo.provider', 'enum' => ['ollama', 'compatible', 'hosted']],
                ['key' => 'budget_enforce', 'type' => 'boolean', 'labelKey' => 'ext.demo.enforce', 'default' => false],
                [
                    'key' => 'budget_tokens', 'type' => 'number', 'labelKey' => 'ext.demo.tokens',
                    'visibleWhen' => ['all' => [['setting' => 'budget_enforce', 'equals' => true]]],
                ],
                ['key' => 'mode', 'type' => 'text', 'labelKey' => 'ext.demo.mode', 'visibility' => 'internal'],
            ]],
            'secrets' => [[
                'key' => 'api_key', 'labelKey' => 'ext.demo.apiKey',
                'visibleWhen' => ['all' => [['setting' => 'provider', 'equals' => 'hosted']]],
            ]],
        ];
    }

    public function testParsesConditionsOnSettingsAndSecrets(): void
    {
        $capabilities = $this->parser->parse($this->manifest($this->capabilities()))->capabilities;

        $this->assertSame(
            ['any' => [['setting' => 'provider', 'equals' => 'compatible'], ['setting' => 'provider', 'equals' => 'ollama']]],
            $capabilities->settings[0]->visibleWhen?->jsonSerialize(),
        );
        $this->assertNull($capabilities->settings[1]->visibleWhen);
        $this->assertSame(
            ['all' => [['setting' => 'provider', 'equals' => 'hosted']]],
            $capabilities->secrets[0]->visibleWhen?->jsonSerialize(),
        );
        $this->assertTrue($capabilities->settings[4]->isInternal());
    }

    public function testStoredProjectionHydratesWithoutChangingItsHash(): void
    {
        $capabilities = $this->parser->parse($this->manifest($this->capabilities()))->capabilities;
        $hydrated = app(ExtensionRuntimePlanService::class)->hydrateCapabilities($capabilities->jsonSerialize());

        $this->assertNotNull($hydrated);
        $this->assertSame($capabilities->hash(), $hydrated->hash());
        $this->assertNotNull($hydrated->settings[0]->visibleWhen);
        $this->assertNotNull($hydrated->secrets[0]->visibleWhen);
    }

    /** A package with no conditions must keep the hash it was installed under. */
    public function testAnUnconditionedFieldSerializesWithoutTheKey(): void
    {
        $capabilities = $this->parser->parse($this->manifest($this->capabilities()))->capabilities;

        $this->assertArrayNotHasKey('visibleWhen', $capabilities->settings[1]->jsonSerialize());
    }

    public function testRejectsAConditionOnAnUndeclaredSetting(): void
    {
        $capabilities = $this->capabilities();
        $capabilities['settings']['fields'][3]['visibleWhen'] = ['all' => [['setting' => 'nope', 'equals' => true]]];

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('does not declare as a readable setting');

        $this->parser->parse($this->manifest($capabilities));
    }

    public function testRejectsAConditionOnASecret(): void
    {
        $capabilities = $this->capabilities();
        $capabilities['settings']['fields'][3]['visibleWhen'] = ['all' => [['secret' => 'api_key', 'configured' => true]]];

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('may only test settings');

        $this->parser->parse($this->manifest($capabilities));
    }

    public function testRejectsAFieldThatDependsOnItself(): void
    {
        $capabilities = $this->capabilities();
        $capabilities['settings']['fields'][3]['visibleWhen'] = ['all' => [['setting' => 'budget_tokens', 'configured' => true]]];

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('depend on itself');

        $this->parser->parse($this->manifest($capabilities));
    }

    public function testRejectsAnEmptyCondition(): void
    {
        $capabilities = $this->capabilities();
        $capabilities['settings']['fields'][3]['visibleWhen'] = ['all' => []];

        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('at least one predicate');

        $this->parser->parse($this->manifest($capabilities));
    }
}
