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
        $hasMessageKey = !empty($data['provider']) && !empty($data['message_id']);
        $hasCorrelationKey = !empty($data['correlation_id']);

        // If required fields are missing, NEVER create a brand new row
        if (!$this->hasRequiredFields($data)) {
            Log::warning('EmailLogService: Incomplete data - will only update existing log', [
                'missing_fields' => $this->getMissingFields($data),
                'has_message_key' => $hasMessageKey,
                'has_correlation_key' => $hasCorrelationKey,
            ]);

            // If we can match an existing row, update it (fill in later)
            if ($hasMessageKey) {
                $existing = EmailLog::where('provider', $data['provider'])
                    ->where('message_id', $data['message_id'])
                    ->first();

                if ($existing) {
                    $existing->update($data);
                    Log::debug('EmailLogService: Updated existing log by message_id', [
                        'id' => $existing->id,
                        'message_id' => $data['message_id'],
                    ]);
                    return $existing;
                }
            }

            if ($hasCorrelationKey) {
                $existing = EmailLog::where('correlation_id', $data['correlation_id'])->first();
                if ($existing) {
                    $existing->update($data);
                    Log::debug('EmailLogService: Updated existing log by correlation_id', [
                        'id' => $existing->id,
                        'correlation_id' => $data['correlation_id'],
                    ]);
                    return $existing;
                }
            }

            // Otherwise: skip entirely
            Log::debug('EmailLogService: Skipping create (incomplete log, no existing row to update)', [
                'missing_fields' => $this->getMissingFields($data),
            ]);
            return null;
        }

        // From here on: safe to create/update
        $uniqueKey = $hasMessageKey
            ? ['provider' => $data['provider'], 'message_id' => $data['message_id']]
            : ['correlation_id' => $data['correlation_id']];

        try {
            $log = EmailLog::updateOrCreate($uniqueKey, $data);

            Log::debug('EmailLogService: Log created/updated with complete data', [
                'id' => $log->id,
                'unique_key' => $uniqueKey,
                'status' => $data['status'] ?? 'unknown',
                'template_key' => $data['template_key'] ?? null,
            ]);

            return $log;
        } catch (\Exception $e) {
            Log::error('EmailLogService: Failed to create/update log', [
                'unique_key' => $uniqueKey,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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
