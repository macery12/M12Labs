<?php

use Illuminate\Support\Facades\Route;
use Everest\Extensions\Packages\server_info\Http\Controllers\ServerInfoController;

Route::group(['prefix' => '/server_info', 'middleware' => ['extensions.access:server_info']], function () {
    Route::get('/', [ServerInfoController::class, 'index']);
});