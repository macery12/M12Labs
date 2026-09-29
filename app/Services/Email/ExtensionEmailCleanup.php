<?php

namespace Everest\Services\Email;

use Everest\Models\EmailNotificationSetting;
use Everest\Services\Email\Templating\EmailTemplateOverrides;

/**
 * What the panel keeps about an extension's email once the extension is gone.
 *
 * A plain uninstall keeps all of it, like the extension's own tables, so a
 * reinstall comes back with the operator's switches, edited templates and
 * hourly limit. Uninstalling with its data dropped removes those. Delivery
 * log rows stay either way: they record mail users actually received, and
 * retention prunes them like every other row.
 */
class ExtensionEmailCleanup
{
    public function __construct(
        private EmailTemplateOverrides $overrides,
        private ExtensionMailLimiter $limiter,
    ) {
    }

    public function forget(string $extensionId): void
    {
        EmailNotificationSetting::query()
            ->whereRaw("template_key LIKE ? ESCAPE '!'", ['ext:' . str_replace('_', '!_', $extensionId) . ':%'])
            ->delete();

        $this->overrides->forgetExtension($extensionId);
        $this->limiter->forget($extensionId);
    }
}
