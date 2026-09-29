<?php

namespace Everest\Http\Controllers\Api\Application;

use Everest\Mail\TestMail;
use Everest\Models\Setting;
use Everest\Facades\Activity;
use Everest\Models\EmailDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Everest\Services\Email\MailFailure;
use Everest\Services\Email\EmailRedactor;
use Everest\Services\Email\EmailCatalogue;
use Everest\Services\Email\DeliveryReceipt;
use Everest\Models\EmailNotificationSetting;
use Everest\Services\Email\EmailPolicyService;
use Illuminate\Validation\ValidationException;
use Everest\Services\Email\EmailSettingsReader;
use Everest\Services\Email\EmailVerificationGate;
use Everest\Services\Email\PanelMailerConfigurator;
use Everest\Http\Requests\Api\Application\Email\SendTestEmailRequest;
use Everest\Http\Requests\Api\Application\Email\TestEmailConnectionRequest;
use Everest\Http\Requests\Api\Application\Email\UpdateEmailSettingsRequest;
use Everest\Http\Requests\Api\Application\Email\UpdateVerificationRulesRequest;
use Everest\Http\Requests\Api\Application\Email\GetEmailNotificationSettingsRequest;
use Everest\Http\Requests\Api\Application\Email\UpdateEmailNotificationSettingRequest;

class EmailController extends ApplicationApiController
{
    private const ACTION_SEND_TEST = 'send_test';
    private const ACTION_CONNECTION_TEST = 'connection_test';

    /**
     * EmailController constructor.
     */
    public function __construct(
        private EmailVerificationGate $verificationGate,
        private EmailSettingsReader $settings,
        private EmailPolicyService $policy,
        private PanelMailerConfigurator $configurator,
    ) {
        parent::__construct();
    }

    /**
     * Get current email settings from database.
     */
    public function getSettings(GetEmailNotificationSettingsRequest $request): JsonResponse
    {
        return response()->json($this->settings->adminSettings());
    }

    public function getVerificationRules(GetEmailNotificationSettingsRequest $request): JsonResponse
    {
        return response()->json($this->verificationGate->getRules());
    }

    public function updateVerificationRules(UpdateVerificationRulesRequest $request): JsonResponse
    {
        $rules = $this->verificationGate->saveRules($request->normalizedRules());

        Activity::event('admin:email:verification-rules:update')
            ->property('rules', $rules)
            ->description('Email verification rules were updated')
            ->log();

        return response()->json($rules);
    }

    /**
     * Update the email settings: providers, sender, delivery switch.
     *
     * @throws \Throwable
     */
    public function updateSettings(UpdateEmailSettingsRequest $request): JsonResponse
    {
        $shouldClearApiKey = $request->boolean('clear_api_key');
        $shouldClearSmtpPassword = $request->boolean('clear_smtp_password');

        foreach ($request->normalize() as $key => $value) {
            // Avoid overwriting an existing key with empty string unless explicitly clearing.
            if ($key === 'modules:email:resend:api_key' && empty($value) && !$shouldClearApiKey) {
                continue;
            }
            if ($key === 'modules:email:smtp:password' && empty($value) && !$shouldClearSmtpPassword) {
                continue;
            }

            Setting::set('settings::' . $key, $value);
        }

        $activitySettings = $request->all();
        $activitySettings = EmailRedactor::redactExactKeys($activitySettings, ['api_key', 'smtp_password']);
        if (array_key_exists('clear_api_key', $activitySettings)) {
            $activitySettings['clear_api_key'] = (bool) $activitySettings['clear_api_key'];
        }
        if (array_key_exists('clear_smtp_password', $activitySettings)) {
            $activitySettings['clear_smtp_password'] = (bool) $activitySettings['clear_smtp_password'];
        }

        Activity::event('admin:email:update')
            ->property('settings', $activitySettings)
            ->description('Email settings were updated')
            ->log();

        return response()->json($this->settings->adminSettings());
    }

    /**
     * Send a real delivery test email to a specific recipient, through the
     * same primary-then-backup path real mail takes. Sent now, not queued,
     * so the admin sees the provider's answer.
     */
    public function sendTest(SendTestEmailRequest $request): JsonResponse
    {
        $recipient = $request->input('to');
        $primary = $this->settings->primary();

        if (!$this->policy->isDeliveryEnabled()) {
            return $this->skipped('disabled', $primary, $recipient);
        }

        if ($this->policy->isBlockedRecipient($recipient)) {
            return $this->skipped('blocked_invalid_recipient', $primary, $recipient);
        }

        try {
            $providers = $this->configurator->configure();
            $sent = Mail::mailer(PanelMailerConfigurator::MAILER)->to($recipient)->send(new TestMail());
        } catch (\Throwable $e) {
            $failure = MailFailure::from($e);

            Activity::event('admin:email:test')
                ->property('to', $recipient)
                ->description('Delivery test email failed')
                ->log();

            return $this->failed($failure, $primary, self::ACTION_SEND_TEST, $recipient);
        }

        $receipt = DeliveryReceipt::from($sent, $providers);

        Activity::event('admin:email:test')
            ->property('to', $recipient)
            ->property('message_id', $receipt->messageId)
            ->description('Delivery test email sent successfully')
            ->log();

        return $this->sent($receipt->provider, self::ACTION_SEND_TEST, $receipt->messageId, $recipient);
    }

    /**
     * Test SMTP connectivity without sending user-facing notifications.
     */
    public function testSmtpConnection(TestEmailConnectionRequest $request): JsonResponse
    {
        return $this->checkConnection('smtp');
    }

    /**
     * Test Resend connectivity without sending user-facing notifications.
     */
    public function testResendConnection(TestEmailConnectionRequest $request): JsonResponse
    {
        return $this->checkConnection('resend');
    }

    /**
     * Send a check message to the sender's own address through one provider
     * alone, so each can be tested whichever of them is primary.
     */
    private function checkConnection(string $provider): JsonResponse
    {
        try {
            $mailer = $this->configurator->configureProvider($provider);
            $sent = Mail::mailer($mailer)->to($this->settings->fromEmail())->send(new TestMail(connectionCheck: true));
        } catch (\Throwable $e) {
            return $this->failed(MailFailure::from($e), $provider, self::ACTION_CONNECTION_TEST);
        }

        return $this->sent($provider, self::ACTION_CONNECTION_TEST, DeliveryReceipt::from($sent, [$provider])->messageId);
    }

    /**
     * Get all email notification settings.
     */
    public function getNotificationSettings(GetEmailNotificationSettingsRequest $request): JsonResponse
    {
        $settings = EmailNotificationSetting::orderBy('category')
            ->orderBy('name')
            ->get()
            ->groupBy('category');

        return response()->json([
            'categories' => $settings,
        ]);
    }

    /**
     * Update a specific email notification setting.
     */
    public function updateNotificationSetting(UpdateEmailNotificationSettingRequest $request, string $id): JsonResponse
    {
        $setting = EmailNotificationSetting::findOrFail($id);
        $enabled = $request->boolean('enabled');

        if (!$enabled && EmailCatalogue::isLocked($setting->template_key)) {
            throw ValidationException::withMessages(['enabled' => "'{$setting->name}' cannot be turned off: without it people cannot get back into their accounts."]);
        }

        $setting->enabled = $enabled;
        $setting->save();

        Activity::event('admin:email:notifications:toggle')
            ->property('template_key', $setting->template_key)
            ->property('enabled', $enabled)
            ->description("Email notification '{$setting->name}' " . ($enabled ? 'enabled' : 'disabled'))
            ->log();

        return response()->json([
            'success' => true,
            'setting' => $setting,
        ]);
    }

    private function sent(string $provider, string $context, ?string $messageId, ?string $recipient = null): JsonResponse
    {
        $payload = [
            'success' => true,
            'action' => $context,
            'transport' => $provider,
            'provider' => $provider,
            'message_id' => $messageId,
            'recipient' => $recipient,
            'status' => EmailDelivery::STATUS_SENT,
            'reason' => null,
            'tested_at' => now()->toIso8601String(),
            'test_type' => $this->getTestType($context),
        ];

        if ($context === self::ACTION_SEND_TEST) {
            $payload['sent_at'] = now()->toIso8601String();
        }

        return response()->json($payload);
    }

    private function skipped(string $reason, string $provider, string $recipient): JsonResponse
    {
        return response()->json([
            'success' => false,
            'action' => self::ACTION_SEND_TEST,
            'transport' => $provider,
            'provider' => $provider,
            'recipient' => $recipient,
            'status' => EmailDelivery::STATUS_SKIPPED,
            'reason' => $reason,
            'error' => [
                'code' => 'EMAIL_DISABLED',
                'status' => 422,
                'message' => $reason,
            ],
            'tested_at' => now()->toIso8601String(),
            'test_type' => $this->getTestType(self::ACTION_SEND_TEST),
        ], 422);
    }

    private function failed(MailFailure $failure, string $provider, string $context, ?string $recipient = null): JsonResponse
    {
        $prefix = strtoupper($provider);

        [$code, $status] = match ($failure->kind) {
            MailFailure::KIND_CONFIG => [$prefix . '_CONFIG_INVALID', 422],
            MailFailure::KIND_AUTH => [$prefix . '_AUTH_FAILED', 422],
            MailFailure::KIND_REJECTED => [$prefix . '_' . strtoupper($context) . '_REJECTED', 422],
            // The provider, not this request, is what failed.
            default => [$prefix . '_' . strtoupper($context) . '_FAILED', 502],
        };

        return response()->json([
            'success' => false,
            'action' => $context,
            'transport' => $provider,
            'provider' => $provider,
            'recipient' => $recipient,
            'status' => EmailDelivery::STATUS_FAILED,
            'reason' => $failure->kind,
            'error' => [
                'code' => $code,
                'status' => $status,
                'message' => $failure->message,
            ],
            'tested_at' => now()->toIso8601String(),
            'test_type' => $this->getTestType($context),
        ], $status);
    }

    private function getTestType(string $context): string
    {
        return $context === self::ACTION_CONNECTION_TEST ? 'connection' : 'delivery';
    }
}
