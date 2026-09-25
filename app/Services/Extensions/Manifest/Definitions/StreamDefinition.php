<?php

namespace Everest\Services\Extensions\Manifest\Definitions;

/**
 * One kind of long-lived event stream a package may open.
 *
 * Named rather than bound to a route, because `capabilities.routes` is a pair
 * of booleans meaning "this package ships routes/client.php" — the manifest
 * never sees a URI, since the route file is what declares those. A package
 * instead names the *kind* of stream it opens and passes that name at runtime,
 * which is also what these limits attach to and what an administrator is shown.
 *
 * The limits are not advisory. An open stream holds a PHP-FPM child for its
 * whole life, which makes this the one extension surface that can exhaust the
 * worker pool by being used exactly as intended — no tampering required. What
 * is declared here is therefore a ceiling the operator sees before installing,
 * and core clamps it again at runtime against its own (see config/extensions.php)
 * so a manifest cannot raise the real limit by asking for more.
 */
final readonly class StreamDefinition implements \JsonSerializable
{
    public function __construct(
        public string $name,
        /** Wall-clock ceiling on one connection. */
        public int $maxSeconds = 300,
        /** How often a comment frame is written while the producer is silent. */
        public int $keepAliveSeconds = 15,
        /** Connections of this kind one user may hold at once. */
        public int $maxConcurrentPerUser = 2,
    ) {
    }

    /** Accounting key for one user's connections of this kind. */
    public function slotName(string $extensionId): string
    {
        return sprintf('ext:%s:%s', $extensionId, $this->name);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'maxSeconds' => $this->maxSeconds,
            'keepAliveSeconds' => $this->keepAliveSeconds,
            'maxConcurrentPerUser' => $this->maxConcurrentPerUser,
        ];
    }
}
