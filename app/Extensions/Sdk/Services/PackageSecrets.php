<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Models\User;
use Everest\Facades\Activity;
use Everest\Models\AdminRole;
use Everest\Extensions\Sdk\DisplayException;
use Everest\Services\Authorization\AdminAuthorizer;
use Everest\Services\Extensions\ExtensionCallerGuard;
use Everest\Services\Extensions\ExtensionSecretStore;

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
 * - **An actor is required.** The write is on behalf of a named user, and that
 *   user must hold `extensions.update` -- the same permission the panel's own
 *   credential form requires, so a package page cannot widen who may set one.
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
     * @throws DisplayException when the key is undeclared or the actor may not
     *                          manage extension credentials
     */
    public function put(User $actor, string $key, #[\SensitiveParameter] string $value): void
    {
        $this->assertWritable($actor, $key);

        if (trim($value) === '') {
            return;
        }

        $this->store->put($this->extensionId, $key, $value, $actor->id);

        $this->audit($actor, 'admin:extensions:secret-update', $key);
    }

    /**
     * Remove a credential, on an administrator's say-so.
     *
     * @throws DisplayException when the key is undeclared or the actor may not
     *                          manage extension credentials
     */
    public function forget(User $actor, string $key): void
    {
        $this->assertWritable($actor, $key);

        if (!$this->store->configured($this->extensionId, $key)) {
            return;
        }

        $this->store->forget($this->extensionId, $key);

        $this->audit($actor, 'admin:extensions:secret-delete', $key);
    }

    private function assertWritable(User $actor, string $key): void
    {
        if (!$this->store->declares($this->extensionId, $key)) {
            throw new DisplayException(sprintf('The extension [%s] does not declare a secret named [%s].', $this->extensionId, $key));
        }

        if (!app(AdminAuthorizer::class)->hasCapability($actor, AdminRole::EXTENSIONS_UPDATE)) {
            throw new DisplayException('Changing an extension credential requires the extensions update permission.');
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
