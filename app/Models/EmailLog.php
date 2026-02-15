<?php

namespace Everest\Models;

/**
 * Everest\Models\EmailLog.
 *
 * @property int $id
 * @property string $to
 * @property string $subject
 * @property string|null $template_key
 * @property string|null $correlation_id
 * @property string|null $message_id
 * @property string $provider
 * @property int|null $user_id
 * @property bool $success
 * @property string|null $error
 * @property string|null $tags
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class EmailLog extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'email_logs';

    protected $fillable = [
        'to',
        'subject',
        'template_key',
        'correlation_id',
        'message_id',
        'provider',
        'user_id',
        'success',
        'error',
        'tags',
    ];

    protected $casts = [
        'success' => 'boolean',
        'tags' => 'array',
        'user_id' => 'integer',
    ];

    /**
     * Get logs by correlation ID for tracing.
     */
    public static function getByCorrelationId(string $correlationId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('correlation_id', $correlationId)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Get logs by template key.
     */
    public static function getByTemplateKey(string $templateKey): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('template_key', $templateKey)
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
