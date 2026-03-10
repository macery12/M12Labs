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

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException|\RuntimeException) {
            // Likely a legacy plaintext or already decrypted value — return as-is.
            return $value;
        }
    }

    public function normalizeKey(string $key): string
    {
        return Str::startsWith($key, 'settings::') ? $key : 'settings::' . ltrim($key, ':');
    }

    private function hasAppKey(): bool
    {
        return !blank(config('app.key'));
    }
}
