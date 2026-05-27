<?php

namespace Everest\Services\Billing;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\PaymentTransaction;
use Everest\Services\Security\LogSanitizer;

class MollieWebhookVerificationService
{
    public function validate(Request $request): array
    {
        $paymentId = $request->input('id');
        $token = $request->query('token');

        if (!is_string($paymentId) || trim($paymentId) === '') {
            return $this->failure(400, 'missing_payment_id');
        }

        if (!is_string($token) || trim($token) === '') {
            return $this->failure(401, 'missing_webhook_token', [
                'payment_id' => LogSanitizer::maskIdentifier($paymentId),
            ]);
        }

        if (!Str::isUuid($token) || !preg_match('/^[A-Za-z0-9_]+$/', $paymentId)) {
            return $this->failure(401, 'invalid_webhook_token', [
                'payment_id' => LogSanitizer::maskIdentifier($paymentId),
            ]);
        }

        $transaction = PaymentTransaction::where('processor', 'mollie')
            ->where('payment_token', $token)
            ->where('external_id', $paymentId)
            ->latest()
            ->first();
        $order = $transaction?->order;

        if (!$order) {
            return $this->failure(401, 'invalid_webhook_token', [
                'payment_id' => LogSanitizer::maskIdentifier($paymentId),
            ]);
        }

        return [
            'valid' => true,
            'payment_id' => $paymentId,
            'order' => $order,
        ];
    }

    private function failure(int $status, string $reason, array $context = []): array
    {
        return [
            'valid' => false,
            'status' => $status,
            'reason' => $reason,
            'context' => $context,
        ];
    }
}
