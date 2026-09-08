<?php

namespace Everest\Tests\Unit\Services\Extensions\Manifest;

use Everest\Tests\TestCase;
use Everest\Services\Extensions\Manifest\ExtensionManifestCanonicalizer;

/**
 * A signature is computed over these bytes, so two manifests that differ only
 * in key order or whitespace must canonicalize identically — otherwise a
 * publisher's signature would not verify against the document the panel parsed.
 */
class ExtensionManifestCanonicalizerTest extends TestCase
{
    private ExtensionManifestCanonicalizer $canonicalizer;

    public function setUp(): void
    {
        parent::setUp();

        $this->canonicalizer = new ExtensionManifestCanonicalizer();
    }

    public function testKeyOrderDoesNotChangeTheOutput(): void
    {
        $a = ['manifestVersion' => 3, 'package' => ['id' => 'demo', 'version' => '1.0.0']];
        $b = ['package' => ['version' => '1.0.0', 'id' => 'demo'], 'manifestVersion' => 3];

        $this->assertSame($this->canonicalizer->canonicalize($a), $this->canonicalizer->canonicalize($b));
    }

    /** List order carries meaning (file order, backoff steps) and is preserved. */
    public function testListOrderIsPreserved(): void
    {
        $a = $this->canonicalizer->canonicalize(['backoff' => [10, 60, 300]]);
        $b = $this->canonicalizer->canonicalize(['backoff' => [300, 60, 10]]);

        $this->assertNotSame($a, $b);
    }

    /** The signature cannot cover itself. */
    public function testTheSignatureIsExcluded(): void
    {
        $unsigned = ['package' => ['id' => 'demo'], 'integrity' => ['keyId' => 'k1']];
        $signed = ['package' => ['id' => 'demo'], 'integrity' => ['keyId' => 'k1', 'signature' => 'abc']];

        $this->assertSame($this->canonicalizer->canonicalize($unsigned), $this->canonicalizer->canonicalize($signed));
    }

    /**
     * An integrity block containing nothing but the stripped signature must
     * canonicalize the same as no block at all, so signing and verification see
     * identical bytes for an otherwise unsigned document.
     */
    public function testAnIntegrityBlockHoldingOnlyASignatureVanishes(): void
    {
        $bare = ['package' => ['id' => 'demo']];
        $signatureOnly = ['package' => ['id' => 'demo'], 'integrity' => ['signature' => 'abc']];

        $this->assertSame($this->canonicalizer->canonicalize($bare), $this->canonicalizer->canonicalize($signatureOnly));
    }

    public function testSlashesAndUnicodeAreNotEscaped(): void
    {
        $output = $this->canonicalizer->canonicalize(['homepage' => 'https://example.invalid/x', 'name' => 'Ünïcode']);

        $this->assertStringContainsString('https://example.invalid/x', $output);
        $this->assertStringContainsString('Ünïcode', $output);
    }

    /** Domain separation stops a signature over one artifact being replayed for another. */
    public function testSigningMessageIsDomainSeparatedAndVersionBound(): void
    {
        $message = $this->canonicalizer->signingMessage('demo', '1.0.0', '{"a":1}');

        $this->assertStringStartsWith("m12labs-ext-v3\ndemo\n1.0.0\n", $message);
        $this->assertStringEndsWith(hash('sha256', '{"a":1}'), $message);

        $this->assertNotSame(
            $message,
            $this->canonicalizer->signingMessage('demo', '1.0.1', '{"a":1}')
        );

        $this->assertNotSame(
            $message,
            $this->canonicalizer->signingMessage('other', '1.0.0', '{"a":1}')
        );
    }

    /**
     * The archive's own hash is deliberately not in the message.
     *
     * The signature ships inside the archive, so covering the archive hash
     * would be circular: writing the signature changes the hash it committed
     * to. A publisher that included it would produce signatures that verify
     * nowhere, which is exactly the bug this asserts against.
     */
    public function testSigningMessageDoesNotCoverTheArchiveHash(): void
    {
        $message = $this->canonicalizer->signingMessage('demo', '1.0.0', '{"a":1}');

        $this->assertSame(4, substr_count($message, "\n") + 1);
        $this->assertStringNotContainsString(str_repeat('a', 64), $message);
    }
}
