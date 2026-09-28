<?php

namespace Everest\Repositories\Eloquent;

use Everest\Models\Setting;
use Illuminate\Foundation\Application;
use Everest\Services\Security\SecretEncryptionService;
use Everest\Contracts\Repository\SettingsRepositoryInterface;

/**
 * Settings for the current operation, read in one query.
 *
 * Boot already loads every row to overlay config, so that load primes the
 * cache and a key it did not return is known to be missing -- a later get()
 * never queries again. Rendering an HTML shell used to issue 29 per-key
 * SELECTs after that boot load had fetched all ~100 rows.
 *
 * Secrets stay ciphertext until something asks for them: boot only needs to
 * know whether one is set, and decrypting every one of them on every request,
 * artisan command and scheduler tick was pure waste.
 *
 * The cache is static, so it has to be dropped between queue jobs
 * ({@see flushCache()}, wired in SettingsServiceProvider); otherwise a
 * Horizon worker would keep sending mail with the SMTP settings it first saw
 * until it recycled an hour later.
 */
class SettingsRepository extends EloquentRepository implements SettingsRepositoryInterface
{
    /** @var array<string, mixed> decrypted values */
    private static array $cache = [];

    /** @var array<string, mixed> secret ciphertext, decrypted on first get() */
    private static array $encrypted = [];

    /** Whether $cache/$encrypted hold every row, so a miss is a real miss. */
    private static bool $loaded = false;

    /**
     * Return the model backing this repository.
     */
    public function model(): string
    {
        return Setting::class;
    }

    public function __construct(Application $app, private SecretEncryptionService $secrets)
    {
        parent::__construct($app);
    }

    /**
     * Store a new persistent setting in the database.
     *
     * @throws \Everest\Exceptions\Model\DataValidationException
     */
    public function set(string $key, ?string $value = null)
    {
        $normalizedKey = $this->secrets->normalizeKey($key);

        if ($this->secrets->isSecretKey($normalizedKey)) {
            $value = $this->secrets->encryptForStorage($value);
        }

        // Clear item from the cache.
        $this->clearCache($normalizedKey);
        $this->withoutFreshModel()->updateOrCreate(['key' => $normalizedKey], ['value' => $value ?? '']);

        $cached = $value;
        if ($this->secrets->isSecretKey($normalizedKey)) {
            $cached = $this->secrets->decryptFromStorage($value);
        }

        self::$cache[$normalizedKey] = $cached;
    }

    /**
     * Retrieve a persistent setting.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $normalizedKey = $this->secrets->normalizeKey($key);

        if (!self::$loaded) {
            $this->loadAll();
        }

        if (array_key_exists($normalizedKey, self::$cache)) {
            return self::$cache[$normalizedKey];
        }

        if (array_key_exists($normalizedKey, self::$encrypted)) {
            $value = $this->secrets->decryptFromStorage(self::$encrypted[$normalizedKey]);
            unset(self::$encrypted[$normalizedKey]);

            return self::$cache[$normalizedKey] = $value;
        }

        return value($default);
    }

    /**
     * Load every setting in one query and prime the cache with it.
     *
     * Returns the stored values as they are in the database -- secrets still
     * encrypted -- for the boot-time config overlay, which only asks whether
     * a secret is set.
     *
     * @return array<string, mixed>
     */
    public function loadAll(): array
    {
        // Reset first: a failed load (no table yet on a fresh install) must
        // not leave a previous operation's values behind.
        self::flushCache();

        $rows = $this->getBuilder()->pluck('value', 'key')->all();

        foreach ($rows as $key => $value) {
            if ($this->secrets->isSecretKey((string) $key)) {
                self::$encrypted[$key] = $value;
            } else {
                self::$cache[$key] = $value;
            }
        }

        self::$loaded = true;

        return $rows;
    }

    /**
     * Drop everything this process has read. The next get() reloads.
     */
    public static function flushCache(): void
    {
        self::$cache = [];
        self::$encrypted = [];
        self::$loaded = false;
    }

    /**
     * Remove a key from the database cache.
     */
    public function forget(string $key)
    {
        $normalizedKey = $this->secrets->normalizeKey($key);

        $this->clearCache($normalizedKey);
        $this->deleteWhere(['key' => $normalizedKey]);
    }

    /**
     * Remove a key from the cache.
     */
    private function clearCache(string $key)
    {
        unset(self::$cache[$key], self::$encrypted[$key]);
    }

    /**
     * Return all settings with secrets transparently decrypted.
     *
     * This overrides the base repository to ensure callers never receive raw
     * encrypted payloads for sensitive keys.
     */
    public function all(): \Illuminate\Support\Collection
    {
        return parent::all()->map(function (Setting $setting) {
            if ($this->secrets->isSecretKey($setting->key)) {
                $setting->value = $this->secrets->decryptFromStorage($setting->value);
            }

            return $setting;
        });
    }
}
