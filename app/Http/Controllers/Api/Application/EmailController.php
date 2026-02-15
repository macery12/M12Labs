<?php

namespace Everest\Http\Controllers\Api\Application;

use Everest\Models\Setting;
use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Everest\Services\Email\EmailManager;
use Everest\Exceptions\Service\Email\ResendException;
use Everest\Http\Requests\Api\Application\Email\UpdateResendSettingsRequest;
use Everest\Http\Requests\Api\Application\Email\SendCustomEmailRequest;
use Everest\Http\Requests\Api\Application\Email\SendTestEmailRequest;

class EmailController extends ApplicationApiController
{
    /**
     * EmailController constructor.
     */
    public function __construct(private EmailManager $emailManager)
    {
        parent::__construct();
    }

    /**
     * Update the Resend email settings.
     *
     * @throws \Throwable
     */
    public function updateSettings(UpdateResendSettingsRequest $request): Response
    {
        foreach ($request->normalize() as $key => $value) {
            // Don't overwrite existing API key with empty string
            // This prevents the placeholder from clearing the real key
            if ($key === 'modules:email:resend:api_key' && empty($value)) {
                continue;
            }
            
            Setting::set('settings::' . $key, $value);
        }

        Activity::event('admin:email:update')
            ->property('settings', $request->all())
            ->description('Resend email settings were updated')
            ->log();

        return $this->returnNoContent();
    }

    /**
     * Send a test email.
     */
    public function sendTest(SendTestEmailRequest $request): JsonResponse
    {
        try {
            $result = $this->emailManager->sendCustom(
                to: $request->input('to'),
                subject: 'Test Email from Resend',
                html: '<h1>Test Email</h1><p>This is a test email from the Resend email system. If you received this, your email configuration is working correctly!</p>'
            );

            if (!$result->success) {
                return response()->json([
                    'success' => false,
                    'error' => $result->error,
                ], 500);
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
                return response()->json([
                    'success' => false,
                    'error' => $result->error,
                ], 500);
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
}
