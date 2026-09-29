<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Everest\Models\EmailDeliveryAttempt.
 *
 * @property int $id
 * @property int $delivery_id
 * @property int $attempt_number
 * @property string|null $provider
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property bool $success
 * @property string $status
 * @property string|null $provider_message_id
 * @property int|null $status_code
 * @property string|null $error
 * @property string|null $exception_class
 * @property string|null $stacktrace
 * @property \Carbon\Carbon $created_at
 * @property EmailDelivery $delivery
 */
class EmailDeliveryAttempt extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'email_delivery_attempts';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    protected $fillable = [
        'delivery_id',
        'attempt_number',
        'provider',
        'started_at',
        'finished_at',
        'duration_ms',
        'success',
        'status',
        'provider_message_id',
        'status_code',
        'error',
        'exception_class',
        'stacktrace',
    ];

    protected $casts = [
        'delivery_id' => 'integer',
        'attempt_number' => 'integer',
        'duration_ms' => 'integer',
        'status_code' => 'integer',
        'success' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Get the delivery this attempt belongs to.
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(EmailDelivery::class, 'delivery_id');
    }

    /**
     * Calculate duration from start to finish.
     */
    public function calculateDuration(): void
    {
        if ($this->started_at && $this->finished_at) {
            $this->duration_ms = (int) $this->started_at->diffInMilliseconds($this->finished_at);
        }
    }
}
