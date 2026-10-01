<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public Auth
    Route::post('/auth/login', [\App\Http\Controllers\Api\V1\AuthController::class, 'login']);

    // Public Event Discovery
    Route::get('/events', [\App\Http\Controllers\Api\V1\EventController::class, 'index']);
    Route::get('/events/{event}', [\App\Http\Controllers\Api\V1\EventController::class, 'show']);

    // Authenticated API
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [\App\Http\Controllers\Api\V1\AuthController::class, 'logout']);
        Route::get('/me', [\App\Http\Controllers\Api\V1\AuthController::class, 'me']);
    });
});
