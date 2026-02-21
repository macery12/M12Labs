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
        // Check global kill switch
        $rawGlobal = Setting::get('settings::modules:email:notifications:global_enabled');
        $globalEnabled = static::toBool($rawGlobal, true);

        if (!$globalEnabled) {
            \Log::info('EmailNotificationSetting: disabled by global toggle', [
                'template_key' => $templateKey,
                'global_enabled_raw' => $rawGlobal,
            ]);
            return false;
        }
        
        $setting = static::where('template_key', $templateKey)->first();

        // Default to enabled when there is no per-template record yet (e.g., missing seed/migration)
        $enabled = $setting ? static::toBool($setting->enabled, true) : true;

        if (!$enabled) {
            \Log::info('EmailNotificationSetting: template disabled', [
                'template_key' => $templateKey,
                'global_enabled_raw' => $rawGlobal,
                'setting_id' => $setting?->id,
                'setting_enabled' => $setting?->enabled,
            ]);
        }

        return $enabled;
    }

    /**
     * Normalize database-stored truthy/falsey values.
     */
    private static function toBool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_null($value)) {
            return $default;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        if ($normalized === '') {
            return $default;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
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
