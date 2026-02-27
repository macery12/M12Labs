<?php

use Illuminate\Support\Facades\Route;
use Everest\Http\Controllers\Api\Client;
use Everest\Http\Middleware\SuspendedAccount;
use Everest\Http\Middleware\Activity\ServerSubject;
use Everest\Http\Middleware\Activity\AccountSubject;
use Everest\Http\Middleware\RequireTwoFactorAuthentication;
use Everest\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;
use Everest\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;

/*
|--------------------------------------------------------------------------
| Client Control API
|--------------------------------------------------------------------------
|
| Endpoint: /api/client
|
*/

Route::prefix('/')->middleware([SuspendedAccount::class])->group(function () {
    Route::get('/', [Client\ClientController::class, 'index'])->name('api:client.index');
    Route::get('/permissions', [Client\ClientController::class, 'permissions']);
    Route::get('links', [Client\LinkController::class, 'index']);
    Route::get('/alerts', [Client\AlertController::class, 'index']);

    Route::prefix('/groups')->group(function () {
        Route::get('/', [Client\ServerGroupController::class, 'index']);
        Route::post('/', [Client\ServerGroupController::class, 'store']);

        Route::patch('/{id}', [Client\ServerGroupController::class, 'update']);
        Route::delete('/{id}', [Client\ServerGroupController::class, 'delete']);

        Route::post('/{id}/add', [Client\ServerGroupController::class, 'add']);
        Route::post('/{id}/remove', [Client\ServerGroupController::class, 'remove']);
    });

    Route::prefix('/account')->middleware(AccountSubject::class)->group(function () {
        Route::prefix('/')->withoutMiddleware(RequireTwoFactorAuthentication::class)->group(function () {
            Route::get('/', [Client\AccountController::class, 'index'])
                ->middleware('verified.view:credentials')
                ->name('api:client.account');
            Route::get('/two-factor', [Client\TwoFactorController::class, 'index'])->middleware('verified.view:credentials');
            Route::post('/two-factor', [Client\TwoFactorController::class, 'store'])->middleware('verified.interact:credentials');
            Route::post('/two-factor/disable', [Client\TwoFactorController::class, 'delete'])->middleware('verified.interact:credentials');
        });

        Route::put('/email', [Client\AccountController::class, 'updateEmail'])
            ->name('api:client.account.update-email');
        Route::put('/password', [Client\AccountController::class, 'updatePassword'])
            ->name('api:client.account.update-password');
        Route::post('/email/verification', [Client\EmailVerificationController::class, 'send'])
            ->name('api:client.account.email-verification')
            ->middleware('throttle:email-verification');

        Route::get('/activity', Client\ActivityLogController::class)
            ->middleware('verified.view:credentials')
            ->name('api:client.account.activity');

        Route::prefix('/sessions')->group(function () {
            Route::get('/', [Client\SessionController::class, 'index'])
                ->middleware('verified.view:credentials')
                ->name('api:client.account.sessions');
            Route::post('/{session}/revoke', [Client\SessionController::class, 'revoke'])
                ->middleware('verified.interact:credentials')
                ->name('api:client.account.sessions.revoke');
            Route::post('/revoke-all', [Client\SessionController::class, 'revokeAll'])
                ->middleware('verified.interact:credentials')
                ->name('api:client.account.sessions.revoke-all');
        });

        Route::get('/api-keys', [Client\ApiKeyController::class, 'index'])->middleware('verified.view:credentials');
        Route::post('/api-keys', [Client\ApiKeyController::class, 'store'])->middleware('verified.interact:credentials');
        Route::delete('/api-keys/{identifier}', [Client\ApiKeyController::class, 'delete'])->middleware('verified.interact:credentials');

        Route::prefix('/ssh-keys')->group(function () {
            Route::get('/', [Client\SSHKeyController::class, 'index'])->middleware('verified.view:credentials');
            Route::post('/', [Client\SSHKeyController::class, 'store'])->middleware('verified.interact:credentials');
            Route::post('/remove', [Client\SSHKeyController::class, 'delete'])->middleware('verified.interact:credentials');
        });

        Route::prefix('/tickets')->group(function () {
            Route::middleware('verified.view:tickets')->group(function () {
                Route::get('/', [Client\TicketController::class, 'index']);
                Route::get('/{ticket:id}', [Client\TicketController::class, 'view']);
            });

            Route::middleware('verified.interact:tickets')->group(function () {
                Route::post('/', [Client\TicketController::class, 'store']);
                Route::delete('/{ticket:id}', [Client\TicketController::class, 'delete']);
                Route::post('/{ticket:id}/messages', [Client\TicketController::class, 'message']);
            });
        });

        Route::prefix('/modpacks')->group(function () {
            Route::get('/search', [Client\AccountModpacksController::class, 'search']);
            Route::get('/compatible-servers', [Client\AccountModpacksController::class, 'getCompatibleServers']);
            Route::get('/{modpackId}', [Client\AccountModpacksController::class, 'getModpack']);
            Route::get('/{modpackId}/files', [Client\AccountModpacksController::class, 'getModpackFiles']);
            Route::get('/minecraft/versions', [Client\AccountModpacksController::class, 'getMinecraftVersions']);
            Route::get('/minecraft/loaders', [Client\AccountModpacksController::class, 'getModLoaderTypes']);
            Route::get('/server/{serverId}/info', [Client\AccountModpacksController::class, 'getServerModpackInfo']);
            Route::post('/install', [Client\AccountModpacksController::class, 'install']);
        });

        Route::post('/setup', [Client\AccountController::class, 'setup']);
    });

    Route::prefix('/billing')->group(function () {
        Route::middleware('verified.view:billing')->group(function () {
            Route::post('/nodes/{product:id}', [Client\Billing\NodesController::class, 'index']);
            Route::get('/categories', [Client\Billing\CategoryController::class, 'index']);

            Route::get('/categories/{id}', [Client\Billing\ProductController::class, 'index']);
            Route::get('/products/{id}', [Client\Billing\ProductController::class, 'view']);
            Route::get('/products/{id}/variables', [Client\Billing\EggController::class, 'index']);
            Route::get('/eggs/{id}', [Client\Billing\EggController::class, 'getEgg']);
            Route::get('/products/{id}/billing-cycles', [Client\Billing\BillingCycleController::class, 'index']);
        });

        Route::middleware(['verified.view:billing', 'verified.interact:billing'])->group(function () {
            // Unified checkout controller for both free and paid products
            Route::get('/products/{id}/key', [Client\Billing\CheckoutController::class, 'getStripeKey']);

            Route::post('/products/{id}/intent', [Client\Billing\CheckoutController::class, 'createIntent']);
            Route::put('/products/{id}/intent', [Client\Billing\CheckoutController::class, 'updateIntent']);

            // Mollie payment routes (webhook route is defined outside this group)
            Route::post('/products/{id}/mollie/payment', [Client\Billing\MollieCheckoutController::class, 'createPayment']);
            Route::put('/products/{id}/mollie/payment', [Client\Billing\MollieCheckoutController::class, 'updatePayment']);
            Route::get('/mollie/status', [Client\Billing\MollieCheckoutController::class, 'checkPaymentStatus']);
            Route::get('/mollie/token/{token}', [Client\Billing\MollieCheckoutController::class, 'getPaymentFromToken']);

            // PayPal payment routes
            Route::post('/products/{id}/paypal/order', [Client\Billing\PayPalCheckoutController::class, 'createOrder']);
            Route::put('/products/{id}/paypal/order', [Client\Billing\PayPalCheckoutController::class, 'updateOrder']);
            Route::post('/paypal/capture', [Client\Billing\PayPalCheckoutController::class, 'captureOrder']);
            Route::get('/paypal/status', [Client\Billing\PayPalCheckoutController::class, 'checkOrderStatus']);
            Route::get('/paypal/token/{token}', [Client\Billing\PayPalCheckoutController::class, 'getOrderFromToken']);

            Route::post('/coupons/validate', [Client\Billing\CouponController::class, 'validateCoupon']);

            Route::post('/process', [Client\Billing\CheckoutController::class, 'processPaid'])->name('api:client.billing.process');
            Route::post('/process/free', [Client\Billing\CheckoutController::class, 'processFree']);
            Route::post('/renew/free', [Client\Billing\CheckoutController::class, 'renewFree']);
        });

        Route::middleware('verified.view:orders')->group(function () {
            Route::get('/orders', [Client\Billing\OrderController::class, 'index']);
            Route::get('/orders/{id}', [Client\Billing\OrderController::class, 'view']);
        });
    });

    Route::prefix('/donations')->middleware('verified.view:donate')->group(function () {
        Route::get('/', [Client\DonationController::class, 'index']);
        Route::middleware('verified.interact:donate')->group(function () {
            Route::get('/key', [Client\DonationController::class, 'getStripeKey']);
            Route::post('/intent', [Client\DonationController::class, 'createIntent']);
            Route::post('/complete', [Client\DonationController::class, 'complete']);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Client Control API
    |--------------------------------------------------------------------------
    |
    | Endpoint: /api/client/servers/{server}
    |
    */
    Route::group([
        'prefix' => '/servers/{server}',
        'middleware' => [
            ServerSubject::class,
            AuthenticateServerAccess::class,
            ResourceBelongsToServer::class,
        ],
    ], function () {
        Route::get('/', [Client\Servers\ServerController::class, 'index'])->name('api:client:server.view');
        Route::get('/websocket', Client\Servers\WebsocketController::class)->name('api:client:server.ws');
        Route::get('/resources', Client\Servers\ResourceUtilizationController::class)->name('api:client:server.resources');
        Route::get('/activity', Client\Servers\ActivityLogController::class)->name('api:client:server.activity');
        Route::get('/activity/users', [Client\Servers\ActivityLogController::class, 'users']);
        Route::get('/activity/events', [Client\Servers\ActivityLogController::class, 'events']);

        Route::post('/command', [Client\Servers\CommandController::class, 'index']);
        Route::post('/power', [Client\Servers\PowerController::class, 'index']);
        Route::post('/ai', [Client\Servers\AIController::class, 'index']);

        Route::group(['prefix' => '/databases'], function () {
            Route::get('/', [Client\Servers\DatabaseController::class, 'index']);
            Route::post('/', [Client\Servers\DatabaseController::class, 'store']);
            Route::post('/{database}/rotate-password', [Client\Servers\DatabaseController::class, 'rotatePassword']);
            Route::delete('/{database}', [Client\Servers\DatabaseController::class, 'delete']);
        });

        Route::group(['prefix' => '/files'], function () {
            Route::get('/list', [Client\Servers\FileController::class, 'directory']);
            Route::get('/contents', [Client\Servers\FileController::class, 'contents']);
            Route::get('/download', [Client\Servers\FileController::class, 'download']);
            Route::put('/rename', [Client\Servers\FileController::class, 'rename']);
            Route::post('/copy', [Client\Servers\FileController::class, 'copy']);
            Route::post('/write', [Client\Servers\FileController::class, 'write']);
            Route::post('/write-with-diff', [Client\Servers\FileController::class, 'writeWithDiff']);
            Route::post('/compress', [Client\Servers\FileController::class, 'compress']);
            Route::post('/decompress', [Client\Servers\FileController::class, 'decompress']);
            Route::post('/delete', [Client\Servers\FileController::class, 'delete']);
            Route::post('/create-folder', [Client\Servers\FileController::class, 'create']);
            Route::post('/chmod', [Client\Servers\FileController::class, 'chmod']);
            Route::post('/pull', [Client\Servers\FileController::class, 'pull'])->middleware(['throttle:10,5']);
            Route::get('/upload', Client\Servers\FileUploadController::class);
        });

        Route::group(['prefix' => '/mods'], function () {
            Route::get('/search', [Client\Servers\ModsController::class, 'search'])->middleware(['throttle:mods.browse']);
            Route::get('/providers', [Client\Servers\ModsController::class, 'providerAccess']);
            Route::get('/{modId}', [Client\Servers\ModsController::class, 'getMod'])->middleware(['throttle:mods.browse']);
            Route::get('/{modId}/files', [Client\Servers\ModsController::class, 'getModFiles'])->middleware(['throttle:mods.browse']);
            Route::post('/{modId}/files/{fileId}/download', [Client\Servers\ModsController::class, 'downloadMod'])->middleware(['throttle:5,1']);
            Route::get('/minecraft/versions', [Client\Servers\ModsController::class, 'getMinecraftVersions'])->middleware(['throttle:mods.meta']);
            Route::get('/minecraft/loaders', [Client\Servers\ModsController::class, 'getModLoaderTypes'])->middleware(['throttle:mods.meta']);
        });

        Route::group(['prefix' => '/modpacks'], function () {
            Route::get('/search', [Client\Servers\ModsController::class, 'searchModpacks'])->middleware(['throttle:mods.browse']);
            Route::get('/{modpackId}', [Client\Servers\ModsController::class, 'getModpack'])->middleware(['throttle:mods.browse']);
            Route::get('/{modpackId}/files', [Client\Servers\ModsController::class, 'getModpackFiles'])->middleware(['throttle:mods.browse']);
            Route::post('/{modpackId}/files/{fileId}/download', [Client\Servers\ModsController::class, 'downloadModpack'])->middleware(['throttle:5,1']);
            Route::get('/minecraft/versions', [Client\Servers\ModsController::class, 'getMinecraftVersions'])->middleware(['throttle:mods.meta']);
            Route::get('/minecraft/loaders', [Client\Servers\ModsController::class, 'getModLoaderTypes'])->middleware(['throttle:mods.meta']);
        });

        Route::group(['prefix' => '/schedules'], function () {
            Route::get('/', [Client\Servers\ScheduleController::class, 'index']);
            Route::post('/', [Client\Servers\ScheduleController::class, 'store']);
            Route::get('/{schedule}', [Client\Servers\ScheduleController::class, 'view']);
            Route::post('/{schedule}', [Client\Servers\ScheduleController::class, 'update']);
            Route::post('/{schedule}/execute', [Client\Servers\ScheduleController::class, 'execute']);
            Route::delete('/{schedule}', [Client\Servers\ScheduleController::class, 'delete']);

            Route::post('/{schedule}/tasks', [Client\Servers\ScheduleTaskController::class, 'store']);
            Route::post('/{schedule}/tasks/{task}', [Client\Servers\ScheduleTaskController::class, 'update']);
            Route::delete('/{schedule}/tasks/{task}', [Client\Servers\ScheduleTaskController::class, 'delete']);
        });

        Route::group(['prefix' => '/network'], function () {
            Route::get('/allocations', [Client\Servers\NetworkAllocationController::class, 'index']);
            Route::post('/allocations', [Client\Servers\NetworkAllocationController::class, 'store']);
            Route::post('/allocations/{allocation}', [Client\Servers\NetworkAllocationController::class, 'update']);
            Route::post('/allocations/{allocation}/primary', [Client\Servers\NetworkAllocationController::class, 'setPrimary']);
            Route::delete('/allocations/{allocation}', [Client\Servers\NetworkAllocationController::class, 'delete']);
        });

        Route::group(['prefix' => '/users'], function () {
            Route::get('/', [Client\Servers\SubuserController::class, 'index']);
            Route::post('/', [Client\Servers\SubuserController::class, 'store']);
            Route::get('/{user}', [Client\Servers\SubuserController::class, 'view']);
            Route::post('/{user}', [Client\Servers\SubuserController::class, 'update']);
            Route::delete('/{user}', [Client\Servers\SubuserController::class, 'delete']);
        });

        Route::group(['prefix' => '/backups'], function () {
            Route::get('/', [Client\Servers\BackupController::class, 'index']);
            Route::post('/', [Client\Servers\BackupController::class, 'store']);
            Route::get('/{backup}', [Client\Servers\BackupController::class, 'view']);
            Route::get('/{backup}/download', [Client\Servers\BackupController::class, 'download']);
            Route::post('/{backup}/lock', [Client\Servers\BackupController::class, 'toggleLock']);
            Route::post('/{backup}/restore', [Client\Servers\BackupController::class, 'restore']);
            Route::delete('/{backup}', [Client\Servers\BackupController::class, 'delete']);
        });

        Route::group(['prefix' => '/startup'], function () {
            Route::get('/', [Client\Servers\StartupController::class, 'index']);
            Route::put('/variable', [Client\Servers\StartupController::class, 'update']);
        });

        Route::group(['prefix' => '/settings'], function () {
            Route::post('/rename', [Client\Servers\SettingsController::class, 'rename']);
            Route::post('/reinstall', [Client\Servers\SettingsController::class, 'reinstall']);
            Route::put('/docker-image', [Client\Servers\SettingsController::class, 'dockerImage']);
            Route::post('/change-egg', [Client\Servers\SettingsController::class, 'changeEgg']);
        });

        Route::group(['prefix' => '/billing'], function () {
            Route::get('/plans', [Client\Billing\PlanChangeController::class, 'getAvailablePlans']);
            Route::get('/plans/{product}/validate', [Client\Billing\PlanChangeController::class, 'validatePlanChange']);
            Route::post('/plans/{product}/change', [Client\Billing\PlanChangeController::class, 'changePlan']);
        });
    });
});
