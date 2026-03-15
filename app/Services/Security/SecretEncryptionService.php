<?php

namespace Everest\Services\Security;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\MissingAppKeyException;

class SecretEncryptionService
{
    /**
     * List of setting keys that should always be stored encrypted.
     * Accepts both prefixed (settings::...) and unprefixed (modules:...) keys.
     */
    private array $secretSettingKeys = [
        'settings::modules:billing:keys:secret',
        'settings::modules:billing:paypal_standalone:client_id',
        'settings::modules:billing:paypal_standalone:client_secret',
        'settings::modules:billing:mollie:api_key',
        'settings::modules:email:resend:api_key',
        'settings::modules:mods:curseforge_api_key',
        'settings::modules:ai:key',
        'settings::modules:auth:discord:client_secret',
        'settings::modules:auth:google:client_secret',
    ];

    public function isSecretKey(string $key): bool
    {
        $normalized = $this->normalizeKey($key);

        return in_array($normalized, $this->secretSettingKeys, true);
    }

    public function encryptForStorage(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (!$this->hasAppKey()) {
            // Without an application key we cannot safely encrypt; let the caller handle it.
            throw new MissingAppKeyException();
        }

        return Crypt::encryptString((string) $value);
    }

    public function decryptFromStorage(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (!$this->hasAppKey()) {
            // When APP_KEY is missing (e.g. key:generate) avoid throwing during boot.
            return null;
        }

        $value = (string) $value;

        try {
            // Handle the case where a secret may have been encrypted multiple times by
            // iteratively decrypting while the payload still looks like a Laravel
            // encrypted string. This prevents returning an encrypted blob to
            // downstream services (e.g. payment processors) which would then fail.
            do {
                $value = Crypt::decryptString($value);
            } while ($this->looksLikeEncryptedPayload($value));
        } catch (DecryptException|\RuntimeException) {
            // Likely a legacy plaintext or already decrypted value — return as-is.
        }

        return $value;
    }

    public function normalizeKey(string $key): string
    {
        return Str::startsWith($key, 'settings::') ? $key : 'settings::' . ltrim($key, ':');
    }

    /**
     * Detects whether the given value appears to be a Laravel encrypted payload.
     */
    private function looksLikeEncryptedPayload(string $value): bool
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        return str_starts_with($decoded, '{"iv":"') && str_contains($decoded, '"value"');
    }

    private function hasAppKey(): bool
    {
        return !blank(config('app.key'));
    }
}
