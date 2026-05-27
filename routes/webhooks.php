<?php

use Illuminate\Support\Facades\Route;
use Everest\Http\Controllers\Webhooks;
use Everest\Http\Middleware\Api\IsValidJson;

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/webhooks
|
| These routes handle incoming webhooks from external payment processors.
| They are intentionally outside all authentication middleware as they
| receive anonymous requests from third-party services.
|
*/

Route::prefix('/webhooks')
    ->middleware([IsValidJson::class, 'throttle:60,1'])
    ->group(function () {
        // Stripe payment / customer webhooks
        Route::post('/stripe', [Webhooks\StripeWebhookController::class, 'handle'])
            ->name('webhook.stripe');

        // PayPal payment webhook
        Route::post('/paypal', [Webhooks\PayPalWebhookController::class, 'handle'])
            ->name('webhook.paypal');

        // Mollie payment webhook
        Route::post('/mollie', [Webhooks\MollieWebhookController::class, 'handle'])
            ->name('webhook.mollie');
    });
