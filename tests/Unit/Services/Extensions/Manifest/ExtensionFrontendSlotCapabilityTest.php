<?php

namespace Everest\Tests\Unit\Services\Extensions\Manifest;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionSignatureService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityDiff;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;
use Everest\Services\Extensions\Manifest\ExtensionManifestCanonicalizer;
use Everest\Services\Extensions\Manifest\Definitions\FrontendSlotDefinition;

/** `capabilities.slots` — package code mounted outside navigable pages. */
class ExtensionFrontendSlotCapabilityTest extends TestCase
{
    private ExtensionManifestParser $parser;

    public function setUp(): void
    {
        parent::setUp();

        $this->parser = new ExtensionManifestParser();
    }

    /** @param array<string, mixed> $capabilities */
    private function manifest(array $capabilities = []): array
    {
        return [
            'manifestVersion' => 3,
            'package' => ['id' => 'demo', 'version' => '1.0.0'],
            'extension' => ['id' => 'demo', 'name' => 'Demo', 'icon' => 'puzzle'],
            'compatiblePanelVersions' => ['>=Alpha 4.1 <Alpha 5.0'],
            'capabilities' => $capabilities,
            'files' => [[
                'path' => 'frontend/src/extensions/packages/demo/slots/assistant-drawer.tsx',
                'sha256' => str_repeat('a', 64),
            ]],
        ];
    }

    public function testItParsesTheTwoInitialServerLayoutSlots(): void
    {
        $manifest = $this->parser->parse($this->manifest([
            'slots' => [
                ['name' => 'server-layout.overlay', 'entry' => 'assistant-drawer', 'order' => 20],
                ['name' => 'server-layout.banner', 'entry' => 'assist-banner', 'order' => 10, 'requiredServerPermission' => 'control.console'],
            ],
        ]));

        $this->assertSame(
            ['server-layout.banner', 'server-layout.overlay'],
            array_map(fn (FrontendSlotDefinition $slot): string => $slot->name, $manifest->capabilities->slots),
        );
        $this->assertSame('control.console', $manifest->capabilities->slots[0]->requiredServerPermission);
        $this->assertSame('assistant-drawer', $manifest->capabilities->slots[1]->entry);
    }

    public function testItRejectsAnUnknownSlotLocation(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('unknown frontend slot');

        $this->parser->parse($this->manifest([
            'slots' => [['name' => 'checkout.payment', 'entry' => 'upsell']],
        ]));
    }

    public function testItRejectsTwoEntriesFromOnePackageInTheSameSlot(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('Duplicate frontend slot');

        $this->parser->parse($this->manifest([
            'slots' => [
                ['name' => 'server-layout.banner', 'entry' => 'one'],
                ['name' => 'server-layout.banner', 'entry' => 'two'],
            ],
        ]));
    }

    public function testAnEntryMustBeAPackageLocalSlug(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('must be a lowercase slug');

        $this->parser->parse($this->manifest([
            'slots' => [['name' => 'server-layout.overlay', 'entry' => '../core']],
        ]));
    }

    public function testAuthorOrderingDoesNotChangeTheApprovedProjection(): void
    {
        $one = $this->parser->parse($this->manifest(['slots' => [
            ['name' => 'server-layout.overlay', 'entry' => 'drawer'],
            ['name' => 'server-layout.banner', 'entry' => 'notice'],
        ]]));
        $two = $this->parser->parse($this->manifest(['slots' => [
            ['name' => 'server-layout.banner', 'entry' => 'notice'],
            ['name' => 'server-layout.overlay', 'entry' => 'drawer'],
        ]]));

        $this->assertSame($one->capabilities->hash(), $two->capabilities->hash());
    }

    /** Existing stored capability hashes must survive a panel upgrade. */
    public function testDeclaringNoSlotsLeavesTheProjectionByteIdentical(): void
    {
        $this->assertArrayNotHasKey('slots', (new ExtensionCapabilitySet(clientRoutes: true))->jsonSerialize());
    }

    public function testAddingAGlobalSlotRequiresFreshApproval(): void
    {
        $diff = ExtensionCapabilityDiff::between(
            new ExtensionCapabilitySet(clientRoutes: true),
            new ExtensionCapabilitySet(
                clientRoutes: true,
                slots: [new FrontendSlotDefinition('server-layout.overlay', 'drawer')],
            ),
        );

        $this->assertContains('slot:server-layout.overlay -> drawer', $diff->escalations);
    }

    public function testAnUnverifiedPackageCannotMountGlobalCode(): void
    {
        $service = new ExtensionSignatureService(new ExtensionManifestCanonicalizer());

        $restricted = $service->restrictedCapabilitiesForUnverified(new ExtensionCapabilitySet(
            slots: [new FrontendSlotDefinition('server-layout.banner', 'notice')],
        ));

        $this->assertContains('slots', $restricted);
    }
}
