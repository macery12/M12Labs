<?php

namespace Everest\Exceptions\Service\Extension;

use Everest\Services\Extensions\Manifest\ExtensionCapabilityVocabulary;

/**
 * A package reached for a privileged core service its live runtime plan does
 * not currently grant.
 *
 * Not a user-facing failure and not rendered as one: it means the package did
 * not declare the service, or a retained facade outlived its live approval.
 * It is thrown rather than returned so the call cannot be mistaken for one
 * that quietly did nothing.
 *
 * Note what this is and is not. Package PHP is trusted, reviewed code running
 * in-process; nothing here contains a package determined to misbehave, and the
 * SDK has never claimed otherwise. What the grant buys is that an administrator
 * saw the privilege before install and can see it afterwards — informed
 * consent, not containment.
 */
class PrivilegeNotGrantedException extends \RuntimeException
{
    public function __construct(public readonly string $extensionId, public readonly string $privilege)
    {
        parent::__construct(sprintf(
            'Extension "%s" does not currently hold the privileged service "%s". '
                . 'The package must be enabled and authentic, and the service must remain in its administrator-approved "capabilities.privileged" set. '
                . 'This panel offers: %s.',
            $extensionId,
            $privilege,
            implode(', ', ExtensionCapabilityVocabulary::PRIVILEGED),
        ));
    }
}
