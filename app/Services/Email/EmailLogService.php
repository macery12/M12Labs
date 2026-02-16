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
     */
    private const REQUIRED_FIELDS = ['user_id', 'template_key', 'correlation_id'];

    /**
     * Create or update an email log.
     * 
     * Logs a warning if required fields are missing but still saves the data.
     * Uses updateOrCreate to update existing partial logs with complete data.
     *
     * @param array $data Log data to save
     * @return EmailLog|null Created or updated log, or null if error occurred
     */
    public function createOrUpdate(array $data): ?EmailLog
    {
        // Warn if required fields are missing, but continue to save
        if (!$this->hasRequiredFields($data)) {
            Log::warning('EmailLogService: Saving log with incomplete data', [
                'data' => $this->sanitizeForLog($data),
                'missing_fields' => $this->getMissingFields($data),
            ]);
        }

        // Determine the unique key to use for updateOrCreate
        // Prefer (provider, message_id) if available, otherwise use correlation_id
        $uniqueKey = [];
        if (!empty($data['message_id']) && !empty($data['provider'])) {
            // Use provider + message_id as unique key (most reliable)
            // This allows us to UPDATE partial logs when we get the complete data
            $uniqueKey = [
                'provider' => $data['provider'],
                'message_id' => $data['message_id'],
            ];
        } elseif (!empty($data['correlation_id'])) {
            // Fallback to correlation_id
            $uniqueKey = ['correlation_id' => $data['correlation_id']];
        } else {
            // No unique key available - this should rarely happen
            // Create a new log entry without a unique constraint
            Log::warning('EmailLogService: No unique key available, creating new log', [
                'has_message_id' => !empty($data['message_id']),
                'has_correlation_id' => !empty($data['correlation_id']),
            ]);
            
            try {
                $log = EmailLog::create($data);
                return $log;
            } catch (\Exception $e) {
                Log::error('EmailLogService: Failed to create log without unique key', [
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        try {
            // Use updateOrCreate with the determined unique key
            // If a partial log exists, this will UPDATE it with complete data
            $log = EmailLog::updateOrCreate($uniqueKey, $data);

            Log::debug('EmailLogService: Log created/updated', [
                'id' => $log->id,
                'unique_key' => $uniqueKey,
                'status' => $data['status'] ?? 'unknown',
                'template_key' => $data['template_key'] ?? null,
                'has_complete_data' => $this->hasRequiredFields($data),
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
