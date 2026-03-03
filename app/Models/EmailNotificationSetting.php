<?php

namespace Everest\Models;

/**
 * Everest\Models\EmailNotificationSetting.
 *
 * @property int $id
 * @property string $template_key
 * @property bool $enabled
 * @property string $category
 * @property string $name
 * @property string|null $description
 * @property bool $rate_limit_exempt
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class EmailNotificationSetting extends Model
{
    public const GLOBAL_ENABLED_SETTING_KEY = 'settings::modules:email:notifications:global_enabled';
    public const GLOBAL_ENABLED_CACHE_KEY = 'email.notifications.global_enabled';
    public const GLOBAL_ENABLED_CACHE_TTL = 60; // seconds
    public const GLOBAL_ENABLED_DEFAULT = 'true';

    protected $table = 'email_notification_settings';

    protected $fillable = [
        'template_key',
        'enabled',
        'category',
        'name',
        'description',
        'rate_limit_exempt',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'rate_limit_exempt' => 'boolean',
    ];

    /**
     * Check if a specific email type is enabled.
     */
    public static function isEnabled(string $templateKey): bool
    {
        // Check global kill switch (cache in shared store to avoid stale per-process values)
        $globalEnabled = cache()->remember(
            self::GLOBAL_ENABLED_CACHE_KEY,
            self::GLOBAL_ENABLED_CACHE_TTL,
            static function () {
                // Avoid the repository's per-process cache so queue workers pick up changes promptly
                $rawGlobal = Setting::query()
                    ->where('key', self::GLOBAL_ENABLED_SETTING_KEY)
                    ->value('value');

                return self::normalizeFlag($rawGlobal);
            }
        );

        if ($globalEnabled !== 'true') {
            return false;
        }
        
        $setting = static::where('template_key', $templateKey)->first();
        
        return $setting ? $setting->enabled : false;
    }

    /**
     * Check if a template is exempt from rate limiting.
     */
    public static function isRateLimitExempt(string $templateKey): bool
    {
        $setting = static::where('template_key', $templateKey)->first();
        
        return $setting ? $setting->rate_limit_exempt : false;
    }

    /**
     * Get all enabled email types by category.
     */
    public static function getEnabledByCategory(string $category): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('category', $category)
            ->where('enabled', true)
            ->get();
    }

    public static function normalizeFlag(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null || $value === '') {
            return self::GLOBAL_ENABLED_DEFAULT;
        }

        $normalized = strtolower((string) $value);

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? 'true' : 'false';
    }
}
