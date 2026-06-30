<?php

use Everest\Http\Controllers\Base;
use Illuminate\Support\Facades\Route;

// Wildcard mount for the standalone v2 UI. Client-side routing under /v2 is
// owned entirely by the SPA, so every path returns the same shell.
Route::get('/{any?}', [Base\IndexController::class, 'v2'])
    ->where('any', '.*')
    ->name('v2');
