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
 * @property string $status
 * @property int $attempt_count
 * @property int|null $duration_ms
 * @property string|null $error
 * @property int|null $status_code
 * @property array|null $tags
 * @property array|null $metadata
 * @property string|null $rendered_subject
 * @property string|null $rendered_html
 * @property string|null $rendered_text
 * @property array|null $template_variables
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
        'status',
        'attempt_count',
        'duration_ms',
        'error',
        'status_code',
        'tags',
        'metadata',
        'rendered_subject',
        'rendered_html',
        'rendered_text',
        'template_variables',
    ];

    protected $casts = [
        'success' => 'boolean',
        'tags' => 'array',
        'metadata' => 'array',
        'template_variables' => 'array',
        'user_id' => 'integer',
        'attempt_count' => 'integer',
        'duration_ms' => 'integer',
        'status_code' => 'integer',
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

    /**
     * Relationship: Get the user associated with this email log.
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: Filter by status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Filter by template key.
     */
    public function scopeWithTemplate($query, string $templateKey)
    {
        return $query->where('template_key', $templateKey);
    }

    /**
     * Scope: Filter by date range.
     */
    public function scopeWithinDateRange($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope: Only failures.
     */
    public function scopeOnlyFailures($query)
    {
        return $query->where('success', false);
    }

    /**
     * Get sanitized template variables (redact sensitive data).
     */
    public function getSanitizedVariables(): array
    {
        $variables = $this->template_variables ?? [];
        $sensitiveKeys = ['resetUrl', 'token', 'api_key', 'password', 'secret'];

        foreach ($variables as $key => $value) {
            foreach ($sensitiveKeys as $sensitive) {
                if (stripos($key, $sensitive) !== false) {
                    $variables[$key] = '[REDACTED]';
                }
            }
        }

        return $variables;
    }
}
