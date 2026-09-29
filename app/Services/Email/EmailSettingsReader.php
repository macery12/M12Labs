<?php

namespace Everest\Services\Email;

use Everest\Models\Setting;

class EmailSettingsReader
{
    public const PROVIDERS = ['smtp', 'resend'];

    public const DEFAULT_LOG_RETENTION_DAYS = 30;

    /**
     * Through the settings repository, which reads every row once per
     * operation and is reset between queue jobs -- this used to query each
     * key on its own, eight SELECTs per page render.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Setting::get($key, $default);
    }

    public function deliveryEnabled(): bool
    {
        $raw = $this->get('settings::modules:email:enabled', false);

        if (is_bool($raw)) {
            return $raw;
        }

        return in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * The provider every message is tried on first.
     */
    public function primary(): string
    {
        $primary = strtolower((string) $this->get('settings::modules:email:primary', 'smtp'));

        return in_array($primary, self::PROVIDERS, true) ? $primary : 'smtp';
    }

    /**
     * The provider a message falls over to when the primary throws, or null
     * when there is none. A backup equal to the primary would only retry the
     * same failure, so it counts as none.
     */
    public function backup(): ?string
    {
        $backup = strtolower((string) $this->get('settings::modules:email:backup', 'none'));

        if (!in_array($backup, self::PROVIDERS, true) || $backup === $this->primary()) {
            return null;
        }

        return $backup;
    }

    /**
     * One sender identity for both providers. Failover hands the same message
     * to the backup, so the message can only carry one From address.
     */
    public function fromEmail(): string
    {
        return trim((string) $this->get('settings::modules:email:from_email', ''));
    }

    public function fromName(): string
    {
        return trim((string) $this->get('settings::modules:email:from_name', ''));
    }

    /**
     * Falls back to the From address, so replies always reach somebody.
     */
    public function replyTo(): string
    {
        return trim((string) $this->get('settings::modules:email:reply_to', '')) ?: $this->fromEmail();
    }

    public function logRetentionDays(): int
    {
        $days = (int) $this->get('settings::modules:email:log_retention_days', self::DEFAULT_LOG_RETENTION_DAYS);

        return $days > 0 ? $days : self::DEFAULT_LOG_RETENTION_DAYS;
    }

    public function adminSettings(): array
    {
        $sender = [
            'from_email' => $this->fromEmail(),
            'from_name' => $this->fromName(),
            'reply_to' => trim((string) $this->get('settings::modules:email:reply_to', '')),
        ];

        return [
            'enabled' => $this->deliveryEnabled(),
            'primary' => $this->primary(),
            'backup' => $this->backup() ?? 'none',
            // The admin screens still read these two shapes; they go when the
            // providers page is rebuilt around primary/backup.
            'transport' => $this->primary(),
            'log_retention_days' => $this->logRetentionDays(),
        ] + $sender + [
            'resend' => [
                'api_key' => !empty($this->get('settings::modules:email:resend:api_key', '')),
            ] + $sender,
            'smtp' => [
                'host' => (string) $this->get('settings::modules:email:smtp:host', ''),
                'port' => (string) $this->get('settings::modules:email:smtp:port', ''),
                'username' => (string) $this->get('settings::modules:email:smtp:username', ''),
                'password_set' => !empty($this->get('settings::modules:email:smtp:password', '')),
                'encryption' => (string) $this->get('settings::modules:email:smtp:encryption', ''),
            ] + $sender,
        ];
    }
}
