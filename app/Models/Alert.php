<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Everest\Models\Alert.
 *
 * @property int $id
 * @property string|null $title
 * @property string $content
 * @property string $type
 * @property string $position
 * @property bool $enabled
 * @property bool $dismissible
 * @property string|null $link
 * @property string|null $link_text
 * @property int $priority
 * @property \Carbon\Carbon|null $start_at
 * @property \Carbon\Carbon|null $end_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Alert extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'alerts';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'title',
        'content',
        'type',
        'position',
        'enabled',
        'dismissible',
        'link',
        'link_text',
        'priority',
        'start_at',
        'end_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'enabled' => 'boolean',
        'dismissible' => 'boolean',
        'priority' => 'integer',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    /**
     * Validation rules for the model.
     */
    public static array $validationRules = [
        'title' => 'nullable|string|max:255',
        'content' => 'required|string|max:1000',
        'type' => 'required|string|in:success,info,warning,danger',
        'position' => 'required|string|in:top-center,bottom-right,bottom-left,center',
        'enabled' => 'boolean',
        'dismissible' => 'boolean',
        'link' => 'nullable|url|max:500',
        'link_text' => 'nullable|string|max:100',
        'priority' => 'integer|min:0',
        'start_at' => 'nullable|date',
        'end_at' => 'nullable|date|after:start_at',
    ];

    /**
     * Scope to get only active alerts (enabled and within date range if set).
     */
    public function scopeActive($query)
    {
        return $query->where('enabled', true)
            ->where(function ($q) {
                $q->whereNull('start_at')
                    ->orWhere('start_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_at')
                    ->orWhere('end_at', '>=', now());
            })
            ->orderByDesc('priority')
            ->orderByDesc('created_at');
    }
}
