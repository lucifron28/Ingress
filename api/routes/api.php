<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public Auth
    Route::post('/auth/login', [\App\Http\Controllers\Api\V1\AuthController::class, 'login'])
        ->middleware('throttle:login');

    // Public Event Discovery
    Route::get('/events', [\App\Http\Controllers\Api\V1\EventController::class, 'index']);
    Route::get('/events/{event}', [\App\Http\Controllers\Api\V1\EventController::class, 'show']);

    // Authenticated API
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [\App\Http\Controllers\Api\V1\AuthController::class, 'logout']);
        Route::get('/me', [\App\Http\Controllers\Api\V1\AuthController::class, 'me']);

        // Attendee Registration & Digital Tickets
        Route::post('/events/{event}/registration', [\App\Http\Controllers\Api\V1\RegistrationController::class, 'store']);
        Route::delete('/events/{event}/registration', [\App\Http\Controllers\Api\V1\RegistrationController::class, 'destroy']);
        Route::get('/me/tickets', [\App\Http\Controllers\Api\V1\TicketController::class, 'myTickets']);
        Route::get('/tickets/{ticket}', [\App\Http\Controllers\Api\V1\TicketController::class, 'show']);

        // Staff Admission & Multi-Gate Scanning
        Route::prefix('staff')->group(function () {
            Route::get('/events', [\App\Http\Controllers\Api\V1\StaffAdmissionController::class, 'assignedEvents']);
            Route::get('/events/{event}/access-points', [\App\Http\Controllers\Api\V1\StaffAdmissionController::class, 'accessPoints']);
            Route::post('/events/{event}/admissions/preview', [\App\Http\Controllers\Api\V1\StaffAdmissionController::class, 'preview']);
            Route::post('/events/{event}/admissions', [\App\Http\Controllers\Api\V1\StaffAdmissionController::class, 'scan']);
        });
    });
});
