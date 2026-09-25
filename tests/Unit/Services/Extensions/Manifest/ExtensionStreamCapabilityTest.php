<?php

namespace Everest\Tests\Unit\Services\Extensions\Manifest;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\ExtensionSignatureService;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityDiff;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;
use Everest\Services\Extensions\Manifest\Definitions\StreamDefinition;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityVocabulary;
use Everest\Services\Extensions\Manifest\ExtensionManifestCanonicalizer;

/**
 * `capabilities.streams` — long-lived connections a package may hold open.
 *
 * The declaration is named rather than attached to a route because the manifest
 * never sees a URI; `capabilities.routes` says only which route *files* ship.
 * What is being tested here is mostly the arithmetic of the limits, and it
 * matters more than it looks: an open stream holds a PHP-FPM child for its whole
 * life, so these numbers are the difference between a busy panel and a wedged
 * one. Every one of them is bounded on the way in, bounded again on the way out
 * of storage, and clamped a third time against the deployment's own ceiling.
 */
class ExtensionStreamCapabilityTest extends TestCase
{
    private ExtensionManifestParser $parser;

    public function setUp(): void
    {
        parent::setUp();

        $this->parser = new ExtensionManifestParser();
    }

    /**
     * @param array<string, mixed> $capabilities
     *
     * @return array<string, mixed>
     */
    private function manifest(array $capabilities = []): array
    {
        return [
            'manifestVersion' => 3,
            'package' => ['id' => 'demo', 'version' => '1.0.0'],
            'extension' => [
                'id' => 'demo',
                'name' => 'Demo',
                'description' => 'A demo package.',
                'icon' => 'puzzle',
                'defaults' => ['enabled' => false, 'allowedNests' => [], 'allowedEggs' => [], 'settings' => []],
            ],
            'compatiblePanelVersions' => ['>=Alpha 4.1 <Alpha 5.0'],
            'capabilities' => $capabilities,
            'files' => [['path' => 'app/Extensions/Packages/demo/routes/client.php', 'sha256' => str_repeat('a', 64)]],
        ];
    }

    public function testADeclaredStreamIsParsedWithItsLimits(): void
    {
        $manifest = $this->parser->parse($this->manifest([
            'streams' => [['name' => 'build-log', 'maxSeconds' => 600, 'keepAliveSeconds' => 10, 'maxConcurrentPerUser' => 1]],
        ]));

        $stream = $manifest->capabilities->streamNamed('build-log');

        $this->assertNotNull($stream);
        $this->assertSame(600, $stream->maxSeconds);
        $this->assertSame(10, $stream->keepAliveSeconds);
        $this->assertSame(1, $stream->maxConcurrentPerUser);
    }

    public function testAnUndeclaredNameIsNotFound(): void
    {
        $manifest = $this->parser->parse($this->manifest(['streams' => [['name' => 'build-log']]]));

        $this->assertNull($manifest->capabilities->streamNamed('something-else'));
    }

    /**
     * The defaults are what an author gets by writing only a name, so they are
     * part of the contract rather than an implementation detail.
     */
    public function testOmittedLimitsFallBackToConservativeDefaults(): void
    {
        $manifest = $this->parser->parse($this->manifest(['streams' => [['name' => 'progress']]]));

        $stream = $manifest->capabilities->streamNamed('progress');

        $this->assertSame(300, $stream->maxSeconds);
        $this->assertSame(15, $stream->keepAliveSeconds);
        $this->assertSame(2, $stream->maxConcurrentPerUser);
    }

    public function testAStreamAskingForMoreThanTheVocabularyAllowsIsRefused(): void
    {
        $this->expectException(DisplayException::class);

        $this->parser->parse($this->manifest([
            'streams' => [['name' => 'forever', 'maxSeconds' => ExtensionCapabilityVocabulary::STREAM_MAX_SECONDS + 1]],
        ]));
    }

    public function testDuplicateNamesAreRefused(): void
    {
        $this->expectException(DisplayException::class);

        $this->parser->parse($this->manifest([
            'streams' => [['name' => 'log'], ['name' => 'log', 'maxSeconds' => 900]],
        ]));
    }

    public function testAnUnknownKeyInsideAStreamIsRefused(): void
    {
        $this->expectException(DisplayException::class);

        $this->parser->parse($this->manifest([
            'streams' => [['name' => 'log', 'maxBytes' => 100]],
        ]));
    }

    /**
     * Two manifests asking for the same thing must project identically, or the
     * hash an administrator approves would depend on typing order.
     */
    public function testTheProjectionDoesNotDependOnAuthorOrdering(): void
    {
        $one = $this->parser->parse($this->manifest(['streams' => [['name' => 'beta'], ['name' => 'alpha']]]));
        $two = $this->parser->parse($this->manifest(['streams' => [['name' => 'alpha'], ['name' => 'beta']]]));

        $this->assertSame($one->capabilities->hash(), $two->capabilities->hash());
        $this->assertSame(['alpha', 'beta'], array_map(fn (StreamDefinition $s): string => $s->name, $one->capabilities->streams));
    }

    /**
     * The compatibility constraint that governs this whole capability.
     *
     * `capability_hash` is taken over the projection at install, and
     * ExtensionRuntimePlanService re-hashes the *stored* copy on every plan
     * build to catch a database edit. An unconditional new key would therefore
     * change the hash of every package installed before streams existed and
     * take the lot inert on upgrade.
     */
    public function testDeclaringNoStreamsLeavesTheProjectionByteIdentical(): void
    {
        $projection = (new ExtensionCapabilitySet(clientRoutes: true))->jsonSerialize();

        $this->assertArrayNotHasKey('streams', $projection);
    }

    public function testDeclaringAStreamChangesTheHashItIsApprovedBy(): void
    {
        $without = new ExtensionCapabilitySet(clientRoutes: true);
        $with = new ExtensionCapabilitySet(clientRoutes: true, streams: [new StreamDefinition('log')]);

        $this->assertNotSame($without->hash(), $with->hash());
    }

    /**
     * Pinned so a change to the projection's shape has to be a decision. If
     * this fails, every installed package with a declared stream is about to
     * become inert; the fix is almost never to update the constant.
     */
    public function testTheProjectionShapeIsPinned(): void
    {
        $set = new ExtensionCapabilitySet(streams: [new StreamDefinition('log', 600, 10, 1)]);

        $this->assertSame(
            ['name' => 'log', 'maxSeconds' => 600, 'keepAliveSeconds' => 10, 'maxConcurrentPerUser' => 1],
            $set->jsonSerialize()['streams'][0],
        );
    }

    /**
     * Not because the package gains reach — it could already serve the same
     * data by polling — but because the cost is a held worker rather than a
     * request, which is the one thing on this list an operator cannot absorb
     * without noticing.
     */
    public function testAddingAStreamOnUpdateIsAnEscalation(): void
    {
        $diff = ExtensionCapabilityDiff::between(
            new ExtensionCapabilitySet(clientRoutes: true),
            new ExtensionCapabilitySet(clientRoutes: true, streams: [new StreamDefinition('log')]),
        );

        $this->assertTrue($diff->isEscalation());
    }

    /** Widening the limits is a different ask, and has to read as one. */
    public function testLengtheningAnExistingStreamIsAnEscalation(): void
    {
        $diff = ExtensionCapabilityDiff::between(
            new ExtensionCapabilitySet(streams: [new StreamDefinition('log', 30)]),
            new ExtensionCapabilitySet(streams: [new StreamDefinition('log', 900)]),
        );

        $this->assertTrue($diff->isEscalation());
    }

    public function testRemovingAStreamIsNotAnEscalation(): void
    {
        $diff = ExtensionCapabilityDiff::between(
            new ExtensionCapabilitySet(streams: [new StreamDefinition('log')]),
            new ExtensionCapabilitySet(),
        );

        $this->assertFalse($diff->isEscalation());
        $this->assertNotEmpty($diff->removed);
    }

    /** An unsigned local package must not be able to hold a worker for an hour. */
    public function testAnUnverifiedPackageMayNotHoldStreams(): void
    {
        $service = new ExtensionSignatureService(new ExtensionManifestCanonicalizer());

        $restricted = $service->restrictedCapabilitiesForUnverified(
            new ExtensionCapabilitySet(streams: [new StreamDefinition('log')])
        );

        $this->assertContains('streams', $restricted);
    }
}
