<?php

use Illuminate\Support\Facades\Route;
use Everest\Extensions\Packages\minecraft_player_manager\Http\Controllers\PlayerManagerController;

/*
|--------------------------------------------------------------------------
| Minecraft Player Manager Extension Routes
|--------------------------------------------------------------------------
*/

Route::group(['prefix' => '/minecraft_player_manager', 'middleware' => ['extensions.access:minecraft_player_manager']], function () {
    Route::get('/', [PlayerManagerController::class, 'index']);

    // Server info
    Route::get('/version', [PlayerManagerController::class, 'getServerVersion']);
    Route::get('/attributes', [PlayerManagerController::class, 'getAttributes']);

    // Player data (v1.0.1)
    Route::get('/player/{player}/data', [PlayerManagerController::class, 'getPlayerData']);
    Route::get('/player/{player}/attribute/{attribute}', [PlayerManagerController::class, 'getAttribute']);
    Route::post('/player/{player}/attribute/{attribute}', [PlayerManagerController::class, 'setAttribute']);
    Route::delete('/player/{player}/attribute/{attribute}', [PlayerManagerController::class, 'resetAttribute']);

    // Whitelist management
    Route::post('/whitelist', [PlayerManagerController::class, 'setWhitelist']);
    Route::put('/whitelist/{player}', [PlayerManagerController::class, 'addWhitelist']);
    Route::delete('/whitelist/{player}', [PlayerManagerController::class, 'removeWhitelist']);

    // Operator management
    Route::put('/op/{player}', [PlayerManagerController::class, 'op']);
    Route::delete('/op/{player}', [PlayerManagerController::class, 'deop']);

    // Ban management
    Route::put('/ban/{player}', [PlayerManagerController::class, 'ban']);
    Route::delete('/ban/{player}', [PlayerManagerController::class, 'unban']);
    Route::put('/ban-ip/{ip}', [PlayerManagerController::class, 'banIp']);
    Route::delete('/ban-ip/{ip}', [PlayerManagerController::class, 'unbanIp']);

    // Player actions
    Route::post('/kick/{player}', [PlayerManagerController::class, 'kick']);
    Route::post('/whisper/{player}', [PlayerManagerController::class, 'whisper']);
    Route::post('/kill/{player}', [PlayerManagerController::class, 'kill']);
});
