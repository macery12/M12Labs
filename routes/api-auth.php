<?php

use Everest\Http\Controllers\Auth\ForgotPasswordController;
use Illuminate\Support\Facades\Route;

Route::prefix('/auth/password-reset')->group(function () {
    Route::get('/method', [ForgotPasswordController::class, 'method'])->name('api.auth.password-reset.method');
    Route::post('/email', [ForgotPasswordController::class, 'requestEmailReset'])
        ->middleware(['throttle:password-reset-ip', 'throttle:password-reset-email'])
        ->name('api.auth.password-reset.email');
    Route::post('/reset', [ForgotPasswordController::class, 'resetWithToken'])
        ->middleware(['throttle:password-reset-ip', 'throttle:password-reset-email'])
        ->name('api.auth.password-reset.reset');
});
