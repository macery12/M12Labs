<?php

namespace Everest\Exceptions\Service\Extension;

use Everest\Exceptions\DisplayException;

/**
 * Raised before a lifecycle mutation when its renewable, fenced lease is no
 * longer owned by this process.
 */
class ExtensionLockLostException extends DisplayException
{
    public function __construct(string $scope)
    {
        parent::__construct(sprintf('The extension %s lease was lost. The operation was stopped before any further shared state was changed.', $scope));
    }
}
