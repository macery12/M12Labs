<?php

use Illuminate\Support\Facades\Route;
use Everest\Http\Controllers\Api\Client\Extensions as ClientExtensions;

/*
|--------------------------------------------------------------------------
| Minecraft Player Manager Extension Routes
|--------------------------------------------------------------------------
*/

Route::group(['prefix' => '/minecraft_player_manager'], function () {
    Route::get('/', [ClientExtensions\PlayerManagerController::class, 'index']);

    // Server info
    Route::get('/version', [ClientExtensions\PlayerManagerController::class, 'getServerVersion']);
    Route::get('/attributes', [ClientExtensions\PlayerManagerController::class, 'getAttributes']);

    // Player data (v1.0.1)
    Route::get('/player/{player}/data', [ClientExtensions\PlayerManagerController::class, 'getPlayerData']);
    Route::get('/player/{player}/attribute/{attribute}', [ClientExtensions\PlayerManagerController::class, 'getAttribute']);
    Route::post('/player/{player}/attribute/{attribute}', [ClientExtensions\PlayerManagerController::class, 'setAttribute']);
    Route::delete('/player/{player}/attribute/{attribute}', [ClientExtensions\PlayerManagerController::class, 'resetAttribute']);

    // Whitelist management
    Route::post('/whitelist', [ClientExtensions\PlayerManagerController::class, 'setWhitelist']);
    Route::put('/whitelist/{player}', [ClientExtensions\PlayerManagerController::class, 'addWhitelist']);
    Route::delete('/whitelist/{player}', [ClientExtensions\PlayerManagerController::class, 'removeWhitelist']);

    // Operator management
    Route::put('/op/{player}', [ClientExtensions\PlayerManagerController::class, 'op']);
    Route::delete('/op/{player}', [ClientExtensions\PlayerManagerController::class, 'deop']);

    // Ban management
    Route::put('/ban/{player}', [ClientExtensions\PlayerManagerController::class, 'ban']);
    Route::delete('/ban/{player}', [ClientExtensions\PlayerManagerController::class, 'unban']);
    Route::put('/ban-ip/{ip}', [ClientExtensions\PlayerManagerController::class, 'banIp']);
    Route::delete('/ban-ip/{ip}', [ClientExtensions\PlayerManagerController::class, 'unbanIp']);

    // Player actions
    Route::post('/kick/{player}', [ClientExtensions\PlayerManagerController::class, 'kick']);
    Route::post('/whisper/{player}', [ClientExtensions\PlayerManagerController::class, 'whisper']);
    Route::post('/kill/{player}', [ClientExtensions\PlayerManagerController::class, 'kill']);
});
