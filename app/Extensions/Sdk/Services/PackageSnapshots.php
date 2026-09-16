<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Models\User;
use Everest\Models\Server;
use Everest\Models\ExtensionFileSnapshot;
use Everest\Services\Extensions\ExtensionFileSnapshotService;

/**
 * Encrypted before-and-after copies of game-server files a package edits.
 *
 * A package that rewrites a config file on a customer's server has destroyed
 * whatever was there. A snapshot taken first is what makes that recoverable,
 * and it is encrypted at rest because those files routinely hold the
 * credentials the package was editing in the first place.
 *
 * Note this has nothing to do with the panel's own file integrity -- these are
 * *game server* files, captured over the daemon, not the extension's installed
 * code.
 *
 * The extension id is bound at construction so a package cannot read another's
 * snapshots by passing a different id, which the underlying service would
 * otherwise allow.
 */
final class PackageSnapshots
{
    private function __construct(
        private string $extensionId,
        private ExtensionFileSnapshotService $service,
    ) {
    }

    public static function for(string $extensionId): self
    {
        return new self($extensionId, app(ExtensionFileSnapshotService::class));
    }

    /**
     * Record the current contents of the files an action is about to change.
     *
     * @param array<string, string> $files path => contents, read before writing
     * @param string $action short verb describing what is about to happen,
     *                       shown to an operator restoring the snapshot
     */
    public function capture(Server $server, ?User $actor, string $action, array $files): ExtensionFileSnapshot
    {
        return $this->service->create($server, $this->extensionId, $actor, $action, $files);
    }

    /** @return array<string, string> path => decrypted contents */
    public function restore(ExtensionFileSnapshot $snapshot): array
    {
        if ($snapshot->extension_id !== $this->extensionId) {
            // Reading another package's snapshot would mean reading the files
            // -- and credentials -- it captured from a customer's server.
            return [];
        }

        return $this->service->decryptFiles($snapshot);
    }
}
