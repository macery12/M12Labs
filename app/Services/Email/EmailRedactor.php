<?php

namespace Everest\Services\Email;

class EmailRedactor
{
    public const REDACTED_VALUE = '[REDACTED]';

    public static function redactExactKeys(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = self::REDACTED_VALUE;
            }
        }

        return $payload;
    }

    public static function redactSensitivePayload(array $payload, array $sensitiveKeys): array
    {
        foreach ($payload as $key => $value) {
            foreach ($sensitiveKeys as $sensitive) {
                if (stripos((string) $key, $sensitive) !== false) {
                    $payload[$key] = self::REDACTED_VALUE;
                    continue 2;
                }
            }

            if (is_array($value)) {
                $payload[$key] = self::redactSensitivePayload($value, $sensitiveKeys);
            }
        }

        return $payload;
    }
}
