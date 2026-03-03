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
    public const GLOBAL_ENABLED_CACHE_KEY = 'email.notifications.global_enabled';
    public const GLOBAL_ENABLED_CACHE_TTL = 60;
    // Default uses normalized string; legacy numeric values remain accepted by allowed list.
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
                    ->where('key', 'settings::modules:email:notifications:global_enabled')
                    ->value('value');

                if ($rawGlobal === null) {
                    return self::GLOBAL_ENABLED_DEFAULT;
                }

                return strtolower((string) $rawGlobal);
            }
        );
        if (!in_array($globalEnabled, ['1', 'true', 'yes', 'on'], true)) {
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
}
