<?php

namespace Everest\Http\Controllers\Api\Application;

use Everest\Models\Setting;
use Everest\Models\EmailNotificationSetting;
use Everest\Models\EmailQuota;
use Everest\Models\User;
use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Everest\Services\Email\EmailManager;
use Everest\Services\Email\EmailVerificationGate;
use Everest\Exceptions\Service\Email\ResendException;
use Everest\Http\Requests\Api\Application\Email\UpdateEmailSettingsRequest;
use Everest\Http\Requests\Api\Application\Email\UpdateVerificationRulesRequest;
use Everest\Http\Requests\Api\Application\Email\SendCustomEmailRequest;
use Everest\Http\Requests\Api\Application\Email\SendTestEmailRequest;

class EmailController extends ApplicationApiController
{
    /**
     * EmailController constructor.
     */
    public function __construct(private EmailManager $emailManager, private EmailVerificationGate $verificationGate)
    {
        parent::__construct();
    }

    /**
     * Get current email settings from database.
     */
    public function getSettings(): JsonResponse
    {
        return response()->json([
            'transport' => EmailManager::getTransport(),
            'enabled' => EmailManager::isDeliveryEnabled(),
            'resend' => [
                'api_key' => !empty(Setting::get('settings::modules:email:resend:api_key', '')),
                'from_email' => Setting::get('settings::modules:email:resend:from_email', ''),
                'from_name' => Setting::get('settings::modules:email:resend:from_name', ''),
                'reply_to' => Setting::get('settings::modules:email:resend:reply_to', ''),
            ],
            'smtp' => [
                'host' => Setting::get('settings::modules:email:smtp:host', ''),
                'port' => Setting::get('settings::modules:email:smtp:port', ''),
                'username' => Setting::get('settings::modules:email:smtp:username', ''),
                'password_set' => !empty(Setting::get('settings::modules:email:smtp:password', '')),
                'encryption' => Setting::get('settings::modules:email:smtp:encryption', ''),
                'from_email' => Setting::get('settings::modules:email:smtp:from_email', ''),
                'from_name' => Setting::get('settings::modules:email:smtp:from_name', ''),
                'reply_to' => Setting::get('settings::modules:email:smtp:reply_to', ''),
            ],
        ]);
    }

    public function getVerificationRules(): JsonResponse
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
     * Update the email settings (transport + provider configs).
     *
     * @throws \Throwable
     */
    public function updateSettings(UpdateEmailSettingsRequest $request): JsonResponse
    {
        foreach ($request->normalize() as $key => $value) {
            // Don't overwrite existing API key with empty string
            // This prevents the placeholder from clearing the real key
            if (in_array($key, ['modules:email:resend:api_key', 'modules:email:smtp:password'], true) && empty($value)) {
                continue;
            }
            
            Setting::set('settings::' . $key, $value);
        }

        $activitySettings = $request->all();
        if (array_key_exists('api_key', $activitySettings)) {
            $activitySettings['api_key'] = '[REDACTED]';
        }
        if (array_key_exists('smtp_password', $activitySettings)) {
            $activitySettings['smtp_password'] = '[REDACTED]';
        }

        Activity::event('admin:email:update')
            ->property('settings', $activitySettings)
            ->description('Email settings were updated')
            ->log();

        // Return updated settings instead of 204
        return $this->getSettings();
    }

    /**
     * Send a test email.
     */
    public function sendTest(SendTestEmailRequest $request): JsonResponse
    {
        try {
            $result = $this->emailManager->sendCustom(
                to: $request->input('to'),
                subject: 'Test Email',
                html: '<h1>Test Email</h1><p>This is a test email from the email system. If you received this, your email configuration is working correctly!</p>'
            );

            if (!$result->success) {
                $error = $result->error
                    ?? $result->reason
                    ?? 'Failed to send test email. Please verify your email module configuration.';

                $statusCode = $result->statusCode
                    ?? (($result->status === 'skipped' || str_contains(strtolower($error), 'disabled')) ? 422 : 500);

                return response()->json([
                    'success' => false,
                    'error' => $error,
                ], $statusCode);
            }

            Activity::event('admin:email:test')
                ->property('to', $request->input('to'))
                ->property('message_id', $result->messageId)
                ->description('Test email sent successfully')
                ->log();

            return response()->json([
                'success' => true,
                'message_id' => $result->messageId,
            ]);
        } catch (ResendException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send a custom email.
     */
    public function sendCustom(SendCustomEmailRequest $request): JsonResponse
    {
        try {
            $result = $this->emailManager->sendCustom(
                to: $request->input('to'),
                subject: $request->input('subject'),
                html: $request->input('html'),
                text: $request->input('text')
            );

            if (!$result->success) {
                $error = $result->error
                    ?? $result->reason
                    ?? 'Failed to send custom email. Please verify your email module configuration.';

                $statusCode = $result->statusCode
                    ?? (($result->status === 'skipped' || str_contains(strtolower($error), 'disabled')) ? 422 : 500);

                return response()->json([
                    'success' => false,
                    'error' => $error,
                ], $statusCode);
            }

            Activity::event('admin:email:custom')
                ->property('to', $request->input('to'))
                ->property('subject', $request->input('subject'))
                ->property('message_id', $result->messageId)
                ->description('Custom email sent successfully')
                ->log();

            return response()->json([
                'success' => true,
                'message_id' => $result->messageId,
            ]);
        } catch (ResendException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all email notification settings.
     */
    public function getNotificationSettings(): JsonResponse
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
    public function updateNotificationSetting(string $id): JsonResponse
    {
        $setting = EmailNotificationSetting::findOrFail($id);
        $enabled = request()->input('enabled');

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

    /**
     * Get email quota information.
     */
    public function getQuotaInfo(): JsonResponse
    {
        // Get aggregate quota stats across all users
        $totalQuotas = EmailQuota::selectRaw('
            plan,
            COUNT(*) as user_count,
            SUM(monthly_sent) as total_monthly_sent,
            SUM(daily_sent) as total_daily_sent,
            SUM(monthly_overage) as total_overage
        ')
            ->groupBy('plan')
            ->get();

        return response()->json([
            'quotas_by_plan' => $totalQuotas,
        ]);
    }

    /**
     * Get email quota for a specific user.
     */
    public function getUserQuota(int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        $quota = EmailQuota::where('user_id', $userId)->first();

        if (!$quota) {
            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'username' => $user->username,
                ],
                'quota' => null,
            ]);
        }

        $remaining = $quota->getRemainingQuota();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'username' => $user->username,
            ],
            'quota' => [
                'plan' => $quota->plan,
                'monthly_limit' => $quota->monthly_limit,
                'daily_limit' => $quota->daily_limit,
                'monthly_sent' => $quota->monthly_sent,
                'daily_sent' => $quota->daily_sent,
                'monthly_overage' => $quota->monthly_overage,
                'remaining' => $remaining,
                'month_reset_at' => $quota->month_reset_at,
                'day_reset_at' => $quota->day_reset_at,
            ],
        ]);
    }

    /**
     * Update user email quota plan.
     */
    public function updateUserQuota(int $userId): JsonResponse
    {
        $plan = request()->input('plan', 'free');

        if (!in_array($plan, ['free', 'pro', 'scale'])) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid plan. Must be one of: free, pro, scale',
            ], 400);
        }

        $quota = EmailQuota::getOrCreateForUser($userId, $plan);
        
        $planConfig = EmailQuota::PLANS[$plan];
        $quota->plan = $plan;
        $quota->monthly_limit = $planConfig['monthly_limit'];
        $quota->daily_limit = $planConfig['daily_limit'];
        $quota->save();

        Activity::event('admin:email:quota:update')
            ->property('user_id', $userId)
            ->property('plan', $plan)
            ->description("Updated email quota plan for user {$userId} to {$plan}")
            ->log();

        return response()->json([
            'success' => true,
            'quota' => $quota,
        ]);
    }
}
