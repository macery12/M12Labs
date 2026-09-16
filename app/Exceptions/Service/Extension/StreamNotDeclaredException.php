<?php

namespace Everest\Exceptions\Service\Extension;

/**
 * A package opened a stream kind its manifest never declared.
 *
 * The same class of failure as {@see PrivilegeNotGrantedException}: the
 * package's code and its manifest disagree, which is a packaging bug rather
 * than something an operator did. Thrown rather than returned, so it cannot be
 * mistaken for a stream that opened and produced nothing.
 *
 * It also fires when the package is no longer enabled, because the limits are
 * read from the live runtime plan on every open — which is deliberate: a
 * disabled package must stop opening new streams even while a worker somewhere
 * is still serving an old one.
 */
class StreamNotDeclaredException extends \RuntimeException
{
    public function __construct(public readonly string $extensionId, public readonly string $stream)
    {
        parent::__construct(sprintf(
            'Extension "%s" opened the stream "%s", which it has not declared. '
                . 'Add it to "capabilities.streams" in the manifest — with its own maxSeconds and '
                . 'maxConcurrentPerUser — and an administrator approves it on install. '
                . 'This also fires when the extension is disabled.',
            $extensionId,
            $stream,
        ));
    }
}
