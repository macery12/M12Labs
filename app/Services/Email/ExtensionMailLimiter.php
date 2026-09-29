<?php

namespace Everest\Services\Email;

use Everest\Models\Setting;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How many emails one extension may queue per hour.
 *
 * Core has no sending quota of its own any more, but a package stuck in a
 * loop would otherwise send until someone noticed -- on the operator's
 * provider account, from the operator's address. The ceiling is the
 * operator's, per extension; the package has no say in it.
 */
class ExtensionMailLimiter
{
    public const DEFAULT_PER_HOUR = 100;

    public const MAX_PER_HOUR = 10000;

    private const DECAY_SECONDS = 3600;

    public function limitFor(string $extensionId): int
    {
        $raw = Setting::get(self::settingKey($extensionId));

        return is_numeric($raw) ? max(1, min((int) $raw, self::MAX_PER_HOUR)) : self::DEFAULT_PER_HOUR;
    }

    public function setLimit(string $extensionId, int $perHour): void
    {
        Setting::set(self::settingKey($extensionId), (string) max(1, min($perHour, self::MAX_PER_HOUR)));
    }

    public function forget(string $extensionId): void
    {
        Setting::forget(self::settingKey($extensionId));
        RateLimiter::clear(self::bucket($extensionId));
    }

    /**
     * Count one send against the hour, or refuse it once the hour is spent.
     * Only called for a message that would otherwise go out, so a switched-off
     * type or a blocked address does not use up the allowance.
     */
    public function attempt(string $extensionId): bool
    {
        return RateLimiter::attempt(self::bucket($extensionId), $this->limitFor($extensionId), fn (): bool => true, self::DECAY_SECONDS);
    }

    /** Sends counted in the current window. */
    public function used(string $extensionId): int
    {
        return RateLimiter::attempts(self::bucket($extensionId));
    }

    private static function settingKey(string $extensionId): string
    {
        return 'settings::modules:email:extension_limit:' . $extensionId;
    }

    private static function bucket(string $extensionId): string
    {
        return 'email:extension:' . $extensionId;
    }
}
