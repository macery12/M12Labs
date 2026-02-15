<?php

namespace Everest\Models;

/**
 * Everest\Models\EmailLog.
 *
 * @property int $id
 * @property string $to
 * @property string $subject
 * @property string|null $message_id
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
        'message_id',
        'success',
        'error',
        'tags',
    ];

    protected $casts = [
        'success' => 'boolean',
        'tags' => 'array',
    ];
}
