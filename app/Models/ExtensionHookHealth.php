<?php

namespace Everest\Models;

/**
 * Delivery health for one (extension, event, handler) triple.
 *
 * Scoped to the handler, not the extension: a failing hook is quarantined on
 * its own, so an extension whose webhook is down keeps its routes, pages and
 * other subscriptions.
 *
 * @property int $id
 * @property string $extension_id
 * @property string $event
 * @property string $handler
 * @property int $invocations
 * @property int $failures
 * @property int $consecutive_failures
 * @property int $total_duration_ms
 * @property \Carbon\Carbon|null $last_invoked_at
 * @property \Carbon\Carbon|null $last_failed_at
 * @property string|null $last_error
 * @property \Carbon\Carbon|null $breaker_open_until
 * @property int $breaker_trips
 * @property \Carbon\Carbon|null $quarantined_at
 */
class ExtensionHookHealth extends Model
{
    protected $table = 'extension_hook_health';

    protected $fillable = [
        'extension_id',
        'event',
        'handler',
        'invocations',
        'failures',
        'consecutive_failures',
        'total_duration_ms',
        'last_invoked_at',
        'last_failed_at',
        'last_error',
        'breaker_open_until',
        'breaker_trips',
        'quarantined_at',
    ];

    protected $casts = [
        'invocations' => 'integer',
        'failures' => 'integer',
        'consecutive_failures' => 'integer',
        'total_duration_ms' => 'integer',
        'breaker_trips' => 'integer',
        'last_invoked_at' => 'datetime',
        'last_failed_at' => 'datetime',
        'breaker_open_until' => 'datetime',
        'quarantined_at' => 'datetime',
    ];

    public static array $validationRules = [
        'extension_id' => 'required|string|max:191',
        'event' => 'required|string|max:64',
        'handler' => 'required|string|max:191',
        'last_error' => 'nullable|string',
    ];
}
