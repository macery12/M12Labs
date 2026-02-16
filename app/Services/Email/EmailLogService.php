<?php

namespace Everest\Services\Email;

use Everest\Models\EmailLog;
use Illuminate\Support\Facades\Log;

/**
 * Centralized email log management service.
 * 
 * Ensures that email logs are only created with complete, validated data.
 * All email logging should go through this service to prevent incomplete or duplicate logs.
 */
class EmailLogService
{
    /**
     * Required fields that must be present before creating a log entry.
     * Note: user_id is NOT required as it can legitimately be NULL for system/admin emails.
     */
    private const REQUIRED_FIELDS = ['template_key', 'correlation_id'];

    /**
     * Create or update an email log.
     * 
     * CRITICAL BEHAVIOR:
     * - If required fields are MISSING: only UPDATE existing rows, never create new ones
     * - If required fields are PRESENT: create or update as normal
     * 
     * This prevents creating incomplete logs while allowing progressive updates.
     *
     * @param array $data Log data to save
     * @return EmailLog|null Created or updated log, or null if skipped
     */
    public function createOrUpdate(array $data): ?EmailLog
    {
        $provider = $data['provider'] ?? null;
        $messageId = $data['message_id'] ?? null;
        $correlationId = $data['correlation_id'] ?? null;

        // If we have correlation_id, always treat it as the primary identity
        if (!empty($correlationId)) {
            // 1) Upsert the correlation row
            $log = EmailLog::updateOrCreate(
                ['correlation_id' => $correlationId],
                $data
            );

            // 2) If we also have message_id, attempt to attach it safely
            if (!empty($provider) && !empty($messageId)) {
                // If another row already owns this provider+message_id, merge into it
                $byMessage = EmailLog::where('provider', $provider)
                    ->where('message_id', $messageId)
                    ->first();

                if ($byMessage && $byMessage->id !== $log->id) {
                    // Merge: keep the byMessage row (unique authority),
                    // copy missing fields from $log, then delete $log
                    $merged = array_filter(array_merge($log->toArray(), $data), fn($v) => $v !== null);

                    $byMessage->update($merged);

                    // Avoid orphan duplicates
                    $log->delete();

                    return $byMessage;
                }

                // Otherwise, safe to set message_id on the correlation row
                $log->update([
                    'provider' => $provider,
                    'message_id' => $messageId,
                ]);
            }

            return $log;
        }

        // No correlation_id: only update/create if we have provider+message_id
        if (!empty($provider) && !empty($messageId)) {
            return EmailLog::updateOrCreate(
                ['provider' => $provider, 'message_id' => $messageId],
                $data
            );
        }

        // Nothing stable to key on: skip
        return null;
    }


    /**
     * Update an existing log by correlation_id.
     * 
     * Only updates if a log with this correlation_id already exists.
     * This prevents creating incomplete logs.
     *
     * @param string $correlationId Correlation ID to find the log
     * @param array $updates Fields to update
     * @return bool Whether the update succeeded
     */
    public function update(string $correlationId, array $updates): bool
    {
        try {
            $affected = EmailLog::where('correlation_id', $correlationId)->update($updates);
            
            if ($affected === 0) {
                Log::debug('EmailLogService: No log found to update', [
                    'correlation_id' => $correlationId,
                ]);
            }

            return $affected > 0;
        } catch (\Exception $e) {
            Log::error('EmailLogService: Failed to update log', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Find a log by correlation_id.
     *
     * @param string $correlationId Correlation ID to search for
     * @return EmailLog|null Found log or null
     */
    public function findByCorrelationId(string $correlationId): ?EmailLog
    {
        return EmailLog::where('correlation_id', $correlationId)->first();
    }

    /**
     * Check if all required fields are present and non-empty.
     *
     * @param array $data Data to validate
     * @return bool True if all required fields present
     */
    private function hasRequiredFields(array $data): bool
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get list of missing required fields.
     *
     * @param array $data Data to check
     * @return array List of missing field names
     */
    private function getMissingFields(array $data): array
    {
        $missing = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    /**
     * Sanitize data for logging (remove sensitive info).
     *
     * @param array $data Data to sanitize
     * @return array Sanitized data
     */
    private function sanitizeForLog(array $data): array
    {
        $sanitized = $data;
        
        // Remove sensitive fields from logs
        $sensitiveFields = ['template_variables', 'rendered_html', 'rendered_text'];
        foreach ($sensitiveFields as $field) {
            if (isset($sanitized[$field])) {
                $sanitized[$field] = '[REDACTED]';
            }
        }

        return $sanitized;
    }

    /**
     * Check if we can create a log with the given data.
     * 
     * Useful for pre-flight validation before attempting to log.
     *
     * @param array $data Data to validate
     * @return array ['valid' => bool, 'missing_fields' => array]
     */
    public function validateLogData(array $data): array
    {
        $valid = $this->hasRequiredFields($data);
        $missingFields = $valid ? [] : $this->getMissingFields($data);

        return [
            'valid' => $valid,
            'missing_fields' => $missingFields,
        ];
    }
}
