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
        $globalEnabled = strtolower((string) Setting::get('settings::modules:email:notifications:global_enabled', '1'));
        if (!in_array($globalEnabled, ['1', 'true', 'yes', 'on'], true)) {
            return false;
        }

        $setting = static::findByTemplateKey($templateKey);
        
        return $setting ? $setting->enabled : false;
    }

    /**
     * Check if a template is exempt from rate limiting.
     */
    public static function isRateLimitExempt(string $templateKey): bool
    {
        $setting = static::findByTemplateKey($templateKey);
        
        return $setting ? $setting->rate_limit_exempt : false;
    }

    public static function normalizeTemplateKey(string $templateKey): string
    {
        return str_replace('.', '_', $templateKey);
    }

    private static function toLegacyDotTemplateKey(string $templateKey): string
    {
        if (str_contains($templateKey, '.')) {
            return $templateKey;
        }

        $parts = explode('_', $templateKey, 2);
        if (count($parts) === 2) {
            return $parts[0] . '.' . $parts[1];
        }

        return $templateKey;
    }

    private static function templateKeyCandidates(string $templateKey): array
    {
        return array_values(array_unique([
            self::normalizeTemplateKey($templateKey),
            self::toLegacyDotTemplateKey($templateKey),
            $templateKey,
        ]));
    }

    private static function findByTemplateKey(string $templateKey): ?self
    {
        $normalized = self::normalizeTemplateKey($templateKey);
        $setting = static::where('template_key', $normalized)->first();
        if ($setting) {
            return $setting;
        }

        $legacy = self::toLegacyDotTemplateKey($templateKey);
        if ($legacy !== $normalized) {
            $setting = static::where('template_key', $legacy)->first();
            if ($setting) {
                return $setting;
            }
        }

        if ($templateKey !== $normalized && $templateKey !== $legacy) {
            return static::where('template_key', $templateKey)->first();
        }

        return null;
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
