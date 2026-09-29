<?php

namespace Everest\Http\Requests\Api\Application\Email;

use Everest\Models\AdminRole;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateEmailSettingsRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return [
            'enabled' => 'boolean',
            'primary' => 'nullable|in:resend,smtp',
            // The providers page before primary/backup: same thing as primary.
            'transport' => 'nullable|in:resend,smtp',
            'backup' => 'nullable|in:none,resend,smtp|different:primary',
            'log_retention_days' => 'nullable|integer|min:1|max:3650',
            'api_key' => 'nullable|string|max:255',
            'clear_api_key' => 'boolean',
            'from_email' => 'nullable|email|max:255',
            'from_name' => 'nullable|string|max:255',
            'reply_to' => 'nullable|email|max:255',
            'smtp_host' => 'nullable|string|max:255',
            'smtp_port' => 'nullable|numeric',
            'smtp_username' => 'nullable|string|max:255',
            'smtp_password' => 'nullable|string|max:255',
            'clear_smtp_password' => 'boolean',
            // Empty string represents no encryption to match UI dropdown default
            'smtp_encryption' => 'nullable|in:,tls,ssl',
        ];
    }

    public function permission(): string
    {
        return AdminRole::EMAIL_UPDATE;
    }

    /**
     * Normalize the request data for storage.
     *
     * Only includes fields that are actually present in the request.
     * This prevents overwriting existing database values with empty strings
     * when doing partial updates (e.g., toggling enabled only).
     */
    public function normalize(?array $only = null): array
    {
        $data = [];

        if ($this->has('enabled')) {
            $data['modules:email:enabled'] = $this->input('enabled', false) ? 'true' : 'false';
        }

        $primary = $this->input('primary') ?? $this->input('transport');
        if ($primary !== null) {
            $data['modules:email:primary'] = $primary;
        }

        if ($this->has('backup')) {
            $data['modules:email:backup'] = $this->input('backup') ?? 'none';
        }

        if ($this->has('log_retention_days')) {
            $data['modules:email:log_retention_days'] = (string) $this->integer('log_retention_days');
        }

        // Resend fields
        $shouldClearApiKey = $this->boolean('clear_api_key');
        if ($shouldClearApiKey) {
            $data['modules:email:resend:api_key'] = '';
        } elseif ($this->has('api_key')) {
            $data['modules:email:resend:api_key'] = $this->input('api_key', '');
        }

        // One sender identity for both providers: failover hands the same
        // message to the backup, so it can only carry one From address.
        foreach (['from_email', 'from_name', 'reply_to'] as $field) {
            if ($this->has($field)) {
                $data["modules:email:{$field}"] = $this->input($field) ?? '';
            }
        }

        // SMTP fields
        if ($this->has('smtp_host')) {
            $data['modules:email:smtp:host'] = $this->input('smtp_host', '');
        }

        if ($this->has('smtp_port')) {
            $data['modules:email:smtp:port'] = $this->input('smtp_port', '');
        }

        if ($this->has('smtp_username')) {
            $data['modules:email:smtp:username'] = $this->input('smtp_username', '');
        }

        $shouldClearSmtpPassword = $this->boolean('clear_smtp_password');
        if ($shouldClearSmtpPassword) {
            $data['modules:email:smtp:password'] = '';
        } elseif ($this->has('smtp_password')) {
            $data['modules:email:smtp:password'] = $this->input('smtp_password', '');
        }

        if ($this->has('smtp_encryption')) {
            $data['modules:email:smtp:encryption'] = $this->input('smtp_encryption', '');
        }

        return $data;
    }
}
