<?php

namespace Everest\Exceptions\Service\Extension;

/**
 * Package code asked for an SDK service in another extension's name.
 *
 * Every `::for($extensionId)` facade scopes what it returns — secrets, settings,
 * streams, privileged services — to the id it is given, and an administrator
 * approved each package's capabilities for that package alone. Accepting any id
 * would let one package spend another's approval, so the panel checks the id
 * against the package the call actually came from.
 *
 * A programming error in the package, never a user-facing failure.
 */
class ForeignExtensionIdException extends \LogicException
{
    public function __construct(public readonly string $callerId, public readonly string $requestedId)
    {
        parent::__construct(sprintf(
            'Extension "%s" asked for an SDK service as extension "%s". A package may only use SDK services in its own name.',
            $callerId,
            $requestedId,
        ));
    }
}
