<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Models\User;
use Everest\Facades\Activity;
use Everest\Extensions\Sdk\DisplayException;
use Everest\Services\Extensions\ExtensionCallerGuard;
use Everest\Services\Extensions\ExtensionSecretStore;
use Everest\Services\Extensions\ExtensionConfigurationAuthorizer;

/**
 * The encrypted secret store, scoped to one extension.
 *
 * Only keys the manifest declares under `capabilities.secrets` exist, so a key
 * this package never declared returns null rather than reaching another
 * extension's value. The extension id is bound at construction rather than
 * passed per call, which is what keeps `get()` from being an arbitrary lookup
 * across the whole table, and `for()` refuses an id other than the calling
 * package's own (see ExtensionCallerGuard). That is defence in depth, not a
 * sandbox: package PHP is trusted code and the real guarantees are review and
 * signing.
 *
 * ## Writing
 *
 * A package with its own settings page -- more than the panel's generated form
 * can carry -- needs to take a credential from the administrator on that page,
 * not send them to a second screen for one field. {@see put()} and
 * {@see forget()} exist for that, and they are shaped so that every value in
 * the store is still one an administrator put there:
 *
 * - **An actor is required.** The write is on behalf of a named user, who
 *   must hold `extensions.update` -- what the panel's own credential form
 *   requires -- or the admin permission the package's settings page is gated
 *   by, passed as `$permission`. That must be one this package declared, so a
 *   package page cannot widen who may set a credential; it can only let an
 *   administrator the operator granted *this package's* permission do so
 *   without also being able to install and remove every extension.
 * - **Audited identically.** The same `admin:extensions:secret-*` events the
 *   panel's form writes, key only, never the value, with `via` naming the
 *   package so the log says which screen it came from.
 * - **Blank is not a write.** An empty value leaves the stored credential
 *   alone, exactly as the panel's form treats an untouched write-only field;
 *   removing one is {@see forget()}, said out loud.
 */
final class PackageSecrets
{
    private function __construct(private string $extensionId, private ExtensionSecretStore $store)
    {
    }

    public static function for(string $extensionId): self
    {
        ExtensionCallerGuard::assertCallerIs($extensionId);

        return new self($extensionId, app(ExtensionSecretStore::class));
    }

    /** The decrypted value, or null when unset or undeclared. */
    public function get(string $key): ?string
    {
        return $this->store->get($this->extensionId, $key);
    }

    /** Whether a value is present, without decrypting it. */
    public function configured(string $key): bool
    {
        return $this->store->configured($this->extensionId, $key);
    }

    /**
     * Store or replace a credential an administrator entered.
     *
     * @param string|null $permission the `ext.<id>.admin.<action>` permission
     *                                the calling page is gated by
     *
     * @throws DisplayException when the key is undeclared or the actor may not
     *                          manage this extension's credentials
     */
    public function put(User $actor, string $key, #[\SensitiveParameter] string $value, ?string $permission = null): void
    {
        $this->assertWritable($actor, $key, $permission);

        if (trim($value) === '') {
            return;
        }

        $this->store->put($this->extensionId, $key, $value, $actor->id);

        $this->audit($actor, 'admin:extensions:secret-update', $key);
    }

    /**
     * Remove a credential, on an administrator's say-so.
     *
     * @param string|null $permission as for {@see put()}
     *
     * @throws DisplayException when the key is undeclared or the actor may not
     *                          manage this extension's credentials
     */
    public function forget(User $actor, string $key, ?string $permission = null): void
    {
        $this->assertWritable($actor, $key, $permission);

        if (!$this->store->configured($this->extensionId, $key)) {
            return;
        }

        $this->store->forget($this->extensionId, $key);

        $this->audit($actor, 'admin:extensions:secret-delete', $key);
    }

    private function assertWritable(User $actor, string $key, ?string $permission): void
    {
        if (!$this->store->declares($this->extensionId, $key)) {
            throw new DisplayException(sprintf('The extension [%s] does not declare a secret named [%s].', $this->extensionId, $key));
        }

        $authorizer = app(ExtensionConfigurationAuthorizer::class);

        if ($permission !== null && !$authorizer->declares($this->extensionId, $permission)) {
            throw new DisplayException(sprintf('The extension [%s] does not declare the admin permission [%s].', $this->extensionId, $permission));
        }

        if (!$authorizer->mayConfigure($actor, $this->extensionId, $permission)) {
            throw new DisplayException('Changing this extension\'s credentials requires the extensions update permission, or the permission its settings page requires.');
        }
    }

    private function audit(User $actor, string $event, string $key): void
    {
        Activity::event($event)
            ->actor($actor)
            ->property('extension_id', $this->extensionId)
            ->property('key', $key)
            ->property('via', $this->extensionId)
            ->log();
    }
}
