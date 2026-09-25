<?php

namespace Everest\Models;

/**
 * One dispatch of an extension job, from queued through to its terminal state.
 *
 * @property int $id
 * @property string|null $job_uuid
 * @property string $extension_id
 * @property string $queue_name
 * @property string $job_class
 * @property string $status
 * @property int $attempts
 * @property string|null $correlation_id
 * @property \Carbon\Carbon|null $dispatched_at
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 * @property string|null $last_error
 */
class ExtensionQueueJob extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_QUARANTINED = 'quarantined';

    /** Work that still counts against a quota and blocks an uninstall. */
    public const IN_FLIGHT = [self::STATUS_QUEUED, self::STATUS_RUNNING];

    protected $table = 'extension_queue_jobs';

    protected $fillable = [
        'job_uuid',
        'extension_id',
        'queue_name',
        'job_class',
        'status',
        'attempts',
        'correlation_id',
        'dispatched_at',
        'started_at',
        'finished_at',
        'last_error',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'dispatched_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public static array $validationRules = [
        'job_uuid' => 'nullable|string|max:36',
        'extension_id' => 'required|string|max:191',
        'queue_name' => 'required|string|max:64',
        'job_class' => 'required|string|max:191',
        // Both carry a column default, and the model validates before the
        // insert applies it.
        'status' => 'sometimes|string|max:16',
        'attempts' => 'sometimes|integer|min:0',
        'correlation_id' => 'nullable|string|max:36',
        'last_error' => 'nullable|string',
    ];
}
