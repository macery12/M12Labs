<?php

namespace Everest\Tests\Unit\Services\Extensions\Manifest;

use Everest\Tests\TestCase;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\Manifest\ExtensionCapabilitySet;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityDiff;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityVocabulary;

/**
 * `capabilities.privileged` — core services a package calls into, rather than
 * surfaces it contributes.
 *
 * Everything else in the vocabulary describes something the package writes and
 * core then mounts: a route, a page, a queue. These go the other way, and what
 * is handed over is authority, so the tests here are about the three things
 * that keeps honest: an unknown name is refused rather than dropped, the
 * projection is stable whatever order the author typed, and asking for one
 * always reaches the administrator as an escalation.
 */
class ExtensionPrivilegedCapabilityTest extends TestCase
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

    public function testADeclaredServiceIsParsedAndGranted(): void
    {
        $manifest = $this->parser->parse($this->manifest(['privileged' => ['internal_dispatch']]));

        $this->assertSame(['internal_dispatch'], $manifest->capabilities->privileged);
        $this->assertTrue($manifest->capabilities->grantsPrivilege('internal_dispatch'));
        $this->assertFalse($manifest->capabilities->grantsPrivilege('delegated_access'));
    }

    /**
     * A package asking for something this panel has never heard of expects
     * behaviour it will not get. Installing it quietly and letting the call
     * fail at runtime is worse than refusing the manifest.
     */
    public function testAnUnknownServiceIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessageMatches('/unknown privileged service "run_anything"/');

        $this->parser->parse($this->manifest(['privileged' => ['run_anything']]));
    }

    public function testTheProjectionDoesNotDependOnHowTheAuthorTypedTheList(): void
    {
        $a = $this->parser->parse($this->manifest(['privileged' => ['internal_dispatch', 'delegated_access']]));
        $b = $this->parser->parse($this->manifest(['privileged' => ['delegated_access', 'internal_dispatch', 'delegated_access']]));

        $this->assertSame($a->capabilities->privileged, $b->capabilities->privileged);
        $this->assertSame(
            $a->capabilities->hash(),
            $b->capabilities->hash(),
            'The capability hash is what an administrator approves; ordering must not change it.',
        );
    }

    /**
     * The compatibility constraint that shapes the whole design.
     *
     * `capability_hash` is taken over this projection at install, and
     * `ExtensionRuntimePlanService` re-hashes the *stored* copy on every plan
     * build to catch a projection edited in the database. An unconditional key
     * would therefore change the hash of every package installed before this
     * existed and take the lot inert on upgrade.
     */
    public function testAPackageAskingForNothingProjectsExactlyAsItDidBefore(): void
    {
        $set = new ExtensionCapabilitySet();

        $this->assertArrayNotHasKey('privileged', $set->jsonSerialize());

        // Pinned against the value this produced before the field existed. If a
        // later change makes the key unconditional, this is the test that says
        // so rather than an upgrade where every installed package goes quiet.
        $this->assertSame(
            '8345c562738d013091735923ac4f048d9eafebd2b714531438b23b2b6b6a0025',
            $set->hash(),
        );
    }

    public function testAskingForOneChangesTheHash(): void
    {
        $this->assertNotSame(
            (new ExtensionCapabilitySet())->hash(),
            (new ExtensionCapabilitySet(privileged: ['internal_dispatch']))->hash(),
        );
    }

    /**
     * The approval dialog is driven entirely by this diff, so a service landing
     * in `escalations` is what makes an administrator see it before an update
     * can widen a package that already had a foot in the door.
     */
    public function testAddingAServiceReachesTheAdministratorAsAnEscalation(): void
    {
        $diff = ExtensionCapabilityDiff::between(
            new ExtensionCapabilitySet(clientRoutes: true),
            new ExtensionCapabilitySet(clientRoutes: true, privileged: ['delegated_access']),
        );

        $this->assertSame(['privileged:delegated_access'], $diff->added);
        $this->assertSame(['privileged:delegated_access'], $diff->escalations);
        $this->assertTrue($diff->isEscalation());
    }

    public function testDroppingAServiceNeedsNoConsent(): void
    {
        $diff = ExtensionCapabilityDiff::between(
            new ExtensionCapabilitySet(privileged: ['delegated_access']),
            new ExtensionCapabilitySet(),
        );

        $this->assertSame(['privileged:delegated_access'], $diff->removed);
        $this->assertFalse($diff->isEscalation());
    }

    public function testEveryNameInTheVocabularyIsAcceptedByTheParser(): void
    {
        $manifest = $this->parser->parse(
            $this->manifest(['privileged' => ExtensionCapabilityVocabulary::PRIVILEGED]),
        );

        $this->assertSame(
            ExtensionCapabilityVocabulary::PRIVILEGED,
            $manifest->capabilities->privileged,
            'The vocabulary and the parser must not drift.',
        );
    }
}
