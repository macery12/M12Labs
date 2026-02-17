<?php

use Everest\Http\Controllers\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
|
| Endpoint: /auth
|
*/

// These routes are defined so that we can continue to reference them programmatically.
// They all route to the same controller function which passes off to React.
Route::get('/login', [Auth\LoginController::class, 'index'])->name('auth.login');
Route::get('/password', [Auth\LoginController::class, 'index'])->name('auth.forgot-password');
Route::get('/password/reset/{token}', [Auth\LoginController::class, 'index'])->name('auth.reset');
Route::prefix('/password-reset')->group(function () {
    Route::get('/method', [Auth\ForgotPasswordController::class, 'method'])->name('auth.password-reset.method');
    Route::post('/email', [Auth\ForgotPasswordController::class, 'requestEmailReset'])
        ->middleware(['throttle:password-reset-ip', 'throttle:password-reset-email', 'captcha'])
        ->name('auth.password-reset.email');
    Route::post('/reset', [Auth\ForgotPasswordController::class, 'resetWithToken'])
        ->middleware(['throttle:password-reset-ip', 'throttle:password-reset-email', 'captcha'])
        ->name('auth.password-reset.reset');
});

// Apply a throttle to authentication action endpoints, in addition to the
// captcha endpoints to slow down manual attack spammers even more. 🤷‍
//
// @see \Everest\Providers\RouteServiceProvider
Route::middleware(['throttle:authentication'])->group(function () {
    // Login endpoints.
    Route::post('/login', [Auth\LoginController::class, 'login'])->middleware('captcha');
    Route::post('/login/checkpoint', Auth\LoginCheckpointController::class)->name('auth.login-checkpoint');

    Route::post('/register', [Auth\LoginController::class, 'register'])->middleware('captcha');
    Route::post('/check-username', [Auth\LoginController::class, 'checkUsername'])
        ->middleware('throttle:10,1') // 10 requests per minute to prevent enumeration
        ->name('auth.check-username');

    Route::post('/modules/discord', [Auth\Modules\DiscordLoginController::class, 'requestToken'])->middleware('captcha');
    Route::get('/modules/discord/authenticate', [Auth\Modules\DiscordLoginController::class, 'authenticate'])
        ->middleware('captcha')
        ->name('auth.modules.discord.authenticate');
    Route::get('/modules/discord/registration-data', [Auth\Modules\DiscordLoginController::class, 'getRegistrationData'])
        ->name('auth.modules.discord.registration-data');
    Route::post('/modules/discord/check-username', [Auth\Modules\DiscordLoginController::class, 'checkUsername'])
        ->name('auth.modules.discord.check-username');
    Route::post('/modules/discord/complete', [Auth\Modules\DiscordLoginController::class, 'completeRegistration'])
        ->middleware('captcha')
        ->name('auth.modules.discord.complete');

    Route::post('/modules/google', [Auth\Modules\GoogleLoginController::class, 'requestToken'])->middleware('captcha');
    Route::get('/modules/google/authenticate', [Auth\Modules\GoogleLoginController::class, 'authenticate'])
        ->middleware('captcha')
        ->name('auth.modules.google.authenticate');

    // Recovery code based password reset endpoint.
    Route::post('/password', [Auth\ForgotPasswordController::class, 'verify'])
        ->name('auth.post.forgot-password')
        ->middleware('captcha');
});

// Password reset routes. This endpoint is hit after going through
// the forgot password routes to acquire a token (or after an account
// is created).
Route::post('/password/reset', Auth\ResetPasswordController::class)->name('auth.reset-password');

// Remove the guest middleware and apply the authenticated middleware to this endpoint,
// so it cannot be used unless you're already logged in.
Route::post('/logout', [Auth\LoginController::class, 'logout'])
    ->withoutMiddleware('guest')
    ->middleware('auth')
    ->name('auth.logout');

// Catch any other combinations of routes and pass them off to the React component.
Route::fallback([Auth\LoginController::class, 'index']);
