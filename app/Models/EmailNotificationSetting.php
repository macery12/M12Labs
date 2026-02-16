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
     * Normalizes template key to underscore format for database lookup.
     */
    public static function isEnabled(string $templateKey): bool
    {
        // Check global kill switch
        $globalEnabled = strtolower((string) Setting::get('settings::modules:email:notifications:global_enabled', '1'));
        if (!in_array($globalEnabled, ['1', 'true', 'yes', 'on'], true)) {
            return false;
        }

        // Normalize to underscore format
        $normalizedKey = str_replace('.', '_', $templateKey);
        
        $setting = static::where('template_key', $normalizedKey)->first();
        
        return $setting ? $setting->enabled : false;
    }

    /**
     * Check if a template is exempt from rate limiting.
     * Normalizes template key to underscore format for database lookup.
     */
    public static function isRateLimitExempt(string $templateKey): bool
    {
        // Normalize to underscore format
        $normalizedKey = str_replace('.', '_', $templateKey);
        
        $setting = static::where('template_key', $normalizedKey)->first();
        
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
