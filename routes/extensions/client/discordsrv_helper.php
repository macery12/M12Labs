<?php

use Illuminate\Support\Facades\Route;
use Everest\Http\Controllers\Api\Client\Extensions as ClientExtensions;

/*
|--------------------------------------------------------------------------
| DiscordSRV Helper Extension Routes
|--------------------------------------------------------------------------
*/

Route::group([
    'prefix' => '/discordsrv_helper',
    'middleware' => ['extensions.access:discordsrv_helper'],
], function () {
    Route::get('/status', [ClientExtensions\DiscordSrvHelperController::class, 'status']);
    Route::post('/install', [ClientExtensions\DiscordSrvHelperController::class, 'install']);
    Route::post('/token', [ClientExtensions\DiscordSrvHelperController::class, 'setToken']);
    Route::post('/channel', [ClientExtensions\DiscordSrvHelperController::class, 'setGlobalChannel']);

    // Owner-only safety controls
    Route::get('/history', [ClientExtensions\DiscordSrvHelperController::class, 'history']);
    Route::post('/history/{snapshotId}/revert', [ClientExtensions\DiscordSrvHelperController::class, 'revert']);
    Route::get('/subusers', [ClientExtensions\DiscordSrvHelperController::class, 'subusers']);
    Route::post('/subusers/{subuserUuid}', [ClientExtensions\DiscordSrvHelperController::class, 'setSubuserAccess']);
});
