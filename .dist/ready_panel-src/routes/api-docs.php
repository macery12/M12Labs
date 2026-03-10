<?php

use Everest\Http\Controllers\Api\ApiDocsController;
use Illuminate\Support\Facades\Route;

Route::get('/openapi.json', [ApiDocsController::class, 'json'])->name('api.docs.openapi');
Route::get('/docs', [ApiDocsController::class, 'docs'])->name('api.docs.ui');
