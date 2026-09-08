<?php

namespace Everest\Models;

/**
 * The payload a queued hook handler reads instead of the rows the FK cascade
 * removed underneath it.
 *
 * @property int $id
 * @property string $correlation_id
 * @property string $extension_id
 * @property string $event
 * @property string $handler
 * @property array $envelope
 * @property \Carbon\Carbon|null $consumed_at
 */
class ExtensionHookTombstone extends Model
{
    protected $table = 'extension_hook_tombstones';

    protected $fillable = [
        'correlation_id',
        'extension_id',
        'event',
        'handler',
        'envelope',
        'consumed_at',
    ];

    protected $casts = [
        'envelope' => 'array',
        'consumed_at' => 'datetime',
    ];

    public static array $validationRules = [
        'correlation_id' => 'required|string|max:36',
        'extension_id' => 'required|string|max:191',
        'event' => 'required|string|max:64',
        'handler' => 'required|string|max:191',
        'envelope' => 'required|array',
        'consumed_at' => 'nullable|date',
    ];
}
