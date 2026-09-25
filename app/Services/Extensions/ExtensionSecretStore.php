<?php

namespace Everest\Services\Extensions;

use Everest\Models\ExtensionSecret;
use Illuminate\Support\Facades\Crypt;
use Everest\Exceptions\DisplayException;
use Illuminate\Contracts\Encryption\DecryptException;
use Everest\Services\Security\SecretEncryptionService;

/**
 * The only place an extension's credentials are written or read.
 *
 * Modelled on CloudflareCredentialService, which is the shape that already
 * works here: configured() / replace() / clear(), and a getter that throws
 * rather than ever falling back to plaintext. The difference is where the
 * ciphertext lives — its own table instead of the settings blob the catalog API
 * returns — and that every access is bound to a declared key.
 *
 * Three rules:
 *
 *  - **Only declared keys.** put() and get() refuse a key the manifest does not
 *    declare under capabilities.secrets, so an update that drops a secret makes
 *    the old value unreachable rather than quietly still readable.
 *  - **Context binding.** Each row carries an HMAC over
 *    extension|key|version. A row copied to another extension or another key
 *    still decrypts to the same bytes, so the copy is refused instead: an
 *    operator with database access cannot hand one extension another's token by
 *    moving a row.
 *  - **No plaintext fallback.** A value that will not decrypt is an error to
 *    re-enter, never an empty string the caller might send as a credential.
 */
class ExtensionSecretStore
{
    public function __construct(private ExtensionRuntimePlanService $plan)
    {
    }

    /** Whether a value is currently stored. Never reveals the value. */
    public function configured(string $extensionId, string $key): bool
    {
        return ExtensionSecret::query()
            ->where('extension_id', $extensionId)
            ->where('key', $key)
            ->exists();
    }

    /**
     * Metadata for every key the extension declares — enough to render the
     * admin form, and nothing more.
     *
     * @return array<int, array{key: string, labelKey: string, helpKey: ?string, rotatable: bool, visibleWhen: ?array<string, mixed>, configured: bool, updatedAt: ?string, rotatedAt: ?string}>
     */
    public function describe(string $extensionId): array
    {
        $rows = ExtensionSecret::query()
            ->where('extension_id', $extensionId)
            ->get()
            ->keyBy('key');

        $described = [];

        foreach ($this->declared($extensionId) as $secret) {
            $row = $rows->get($secret->key);

            $described[] = [
                'key' => $secret->key,
                'labelKey' => $secret->labelKey,
                'helpKey' => $secret->helpKey,
                'rotatable' => $secret->rotatable,
                'visibleWhen' => $secret->visibleWhen?->jsonSerialize(),
                'configured' => $row !== null,
                'updatedAt' => $row?->updated_at?->toIso8601String(),
                'rotatedAt' => $row?->rotated_at?->toIso8601String(),
            ];
        }

        return $described;
    }

    /**
     * Store or replace a credential.
     *
     * An empty value is a no-op rather than a delete: the admin form submits
     * write-only fields, so a blank field means "unchanged", and treating it as
     * "clear" would silently destroy a working credential on any unrelated save.
     */
    public function put(string $extensionId, string $key, #[\SensitiveParameter] string $value, ?int $updatedBy = null): void
    {
        $this->assertDeclared($extensionId, $key);

        if (trim($value) === '') {
            return;
        }

        $existing = ExtensionSecret::query()
            ->where('extension_id', $extensionId)
            ->where('key', $key)
            ->first();

        $version = $existing === null ? 1 : $existing->key_version + 1;

        ExtensionSecret::query()->updateOrCreate(
            ['extension_id' => $extensionId, 'key' => $key],
            [
                'value' => app(SecretEncryptionService::class)->encryptForStorage(trim($value)),
                'key_version' => $version,
                'context_hash' => $this->contextHash($extensionId, $key, $version),
                'rotated_at' => $existing === null ? null : now(),
                'updated_by' => $updatedBy,
            ]
        );
    }

    /**
     * Read a credential. Returns null when nothing is stored.
     *
     * @throws DisplayException when the stored value cannot be trusted
     */
    public function get(string $extensionId, string $key): ?string
    {
        $this->assertDeclared($extensionId, $key);

        $row = ExtensionSecret::query()
            ->where('extension_id', $extensionId)
            ->where('key', $key)
            ->first();

        if ($row === null) {
            return null;
        }

        if (blank(config('app.key'))) {
            throw new DisplayException('An extension credential could not be decrypted. Configure the application encryption key.');
        }

        if (!hash_equals($this->contextHash($extensionId, $key, $row->key_version), (string) $row->context_hash)) {
            throw new DisplayException(sprintf('The stored credential for [%s.%s] does not belong to it. Re-enter the credential.', $extensionId, $key));
        }

        try {
            return Crypt::decryptString($row->value);
        } catch (DecryptException) {
            // Never attach the original exception, and never fall back to the
            // raw column: a caller handed ciphertext as a credential would send
            // it to whatever service the extension talks to.
            throw new DisplayException(sprintf('The stored credential for [%s.%s] could not be decrypted. Re-enter it.', $extensionId, $key));
        }
    }

    public function forget(string $extensionId, string $key): void
    {
        ExtensionSecret::query()
            ->where('extension_id', $extensionId)
            ->where('key', $key)
            ->delete();
    }

    /**
     * Destroy every credential an extension holds. Called unconditionally on
     * uninstall — a credential outliving the extension that used it is a
     * standing liability nobody is watching.
     */
    public function purge(string $extensionId): int
    {
        return ExtensionSecret::query()->where('extension_id', $extensionId)->delete();
    }

    /**
     * Re-encrypt every row under the current APP_KEY, using the given decryptor
     * for the old one. Used by p:ext:secrets:rewrap during a key rotation.
     *
     * @param callable(string): string $decryptWithPreviousKey
     *
     * @return array{rewrapped: int, failed: array<int, string>}
     */
    public function rewrap(callable $decryptWithPreviousKey): array
    {
        $rewrapped = 0;
        $failed = [];

        foreach (ExtensionSecret::query()->get() as $row) {
            try {
                $plaintext = $decryptWithPreviousKey($row->value);
            } catch (\Throwable) {
                $failed[] = $row->extension_id . '.' . $row->key;

                continue;
            }

            $row->forceFill([
                'value' => Crypt::encryptString($plaintext),
                // The context hash is over identity and version, not over the
                // key material, so a rewrap leaves it correct.
                'context_hash' => $this->contextHash($row->extension_id, $row->key, $row->key_version),
            ])->saveQuietly();

            ++$rewrapped;
        }

        return ['rewrapped' => $rewrapped, 'failed' => $failed];
    }

    /** Whether the extension's verified manifest declares this key. */
    public function declares(string $extensionId, string $key): bool
    {
        foreach ($this->declared($extensionId) as $secret) {
            if ($secret->key === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, Manifest\Definitions\SecretDefinition>
     */
    private function declared(string $extensionId): array
    {
        return $this->plan->entry($extensionId)?->capabilities->secrets ?? [];
    }

    private function assertDeclared(string $extensionId, string $key): void
    {
        if ($this->declares($extensionId, $key)) {
            return;
        }

        throw new DisplayException(sprintf('The extension [%s] does not declare a secret named [%s].', $extensionId, $key));
    }

    private function contextHash(string $extensionId, string $key, int $version): string
    {
        return hash_hmac('sha256', sprintf('%s|%s|%d', $extensionId, $key, $version), (string) config('app.key'));
    }
}
