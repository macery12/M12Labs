<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Facades\Activity;
use Everest\Models\ActivityLog;
use Everest\Services\Activity\ActivityLogService;

/**
 * Writing to the panel's activity log, under the calling extension's own
 * namespace.
 *
 * The namespace is the reason this exists. Packages previously logged straight
 * through the Activity facade and chose their own event names, which put them
 * in core's `server:` namespace -- minecraft_startup_editor wrote
 * `server:startup.command` next to core's own `server:startup.edit`, and
 * minecraft_player_manager wrote eleven more. Two problems with that: an
 * operator reading the log cannot tell a core action from an extension's, and
 * two packages can collide on a name with nothing to stop them.
 *
 * Every event written through here becomes `ext:<id>:<event>`. The prefix comes
 * from the extension id passed to `for()` and the caller cannot include a colon
 * in the event name, so a package cannot spell its way back into core's
 * namespace or into another extension's.
 *
 * Rows written before this class existed keep their original keys. They are not
 * rewritten: a migration over the activity table would break saved operator
 * filters and would restate history under a scheme that did not exist when
 * those events happened.
 */
final class PanelActivity
{
    private function __construct(private string $extensionId)
    {
    }

    public static function for(string $extensionId): self
    {
        return new self($extensionId);
    }

    /**
     * Begin an entry. Chain ->subject(), ->property() and ->log() as with core.
     *
     * @param string $event an unqualified name such as "player.ban"; the
     *                      `ext:<id>:` prefix is added here
     */
    public function event(string $event): ActivityLogService
    {
        return Activity::event($this->qualify($event));
    }

    /** Convenience for the common shape: one event, some properties, logged. */
    public function record(string $event, array $properties = [], ?string $description = null): ?ActivityLog
    {
        return Activity::event($this->qualify($event))
            ->property($properties)
            ->log($description);
    }

    private function qualify(string $event): string
    {
        // Colons are the namespace separator, so stripping them is what makes
        // the prefix a guarantee rather than a convention a package can escape.
        $event = str_replace(':', '.', trim($event));

        return sprintf('ext:%s:%s', $this->extensionId, $event);
    }
}
