<?php

namespace Everest\Models;

use Everest\Services\Email\EmailCatalogue;

/**
 * Everest\Models\EmailNotificationSetting.
 *
 * @property int $id
 * @property string $template_key
 * @property bool $enabled
 * @property string $category
 * @property string $name
 * @property string|null $description
 * @property bool $locked
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
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    protected $appends = ['locked'];

    /**
     * A locked type sends regardless of its toggle, and the toggle cannot be
     * turned off (see EmailCatalogue).
     */
    public function getLockedAttribute(): bool
    {
        return EmailCatalogue::isLocked($this->template_key);
    }

    /**
     * Check if a specific template is enabled (ignores global switch).
     */
    public static function isTemplateEnabled(string $templateKey): bool
    {
        $setting = static::where('template_key', $templateKey)->first();

        return $setting ? (bool) $setting->enabled : false;
    }
}
