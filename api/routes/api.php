<?php

use App\Http\Controllers\Api\V1\AdmissionSyncController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\NodeStaffAuthController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\StaffAdmissionController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\StudentEmailVerificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/node-sync/admissions', [AdmissionSyncController::class, 'receive'])
        ->middleware(['runtime:cloud', 'throttle:60,1']);
    Route::post('/node-sync/walk-ins', [AdmissionSyncController::class, 'receiveWalkIn'])
        ->middleware(['runtime:cloud', 'throttle:60,1']);

    // Public Authentication
    Route::post('/auth/student-verification', [StudentEmailVerificationController::class, 'requestLink'])
        ->middleware(['runtime:cloud', 'throttle:student-email']);

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware(['runtime:legacy,cloud', 'throttle:login']);
    Route::post('/auth/node-staff/login', [NodeStaffAuthController::class, 'login'])
        ->middleware(['runtime:node', 'throttle:node-staff-login']);
    Route::post('/auth/node-staff/pair', [NodeStaffAuthController::class, 'pair'])
        ->middleware(['runtime:node', 'throttle:node-staff-pair']);

    // Authenticated API
    Route::middleware(['auth:sanctum', 'node.staff'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        // Event Discovery
        Route::get('/events', [EventController::class, 'index']);
        Route::get('/events/{event}', [EventController::class, 'show']);

        // Attendee Registration & Digital Tickets
        Route::post('/events/{event}/registration', [RegistrationController::class, 'store'])->middleware('runtime:legacy,cloud');
        Route::get('/me/tickets', [TicketController::class, 'myTickets']);
        Route::get('/tickets/{ticket}', [TicketController::class, 'show']);

        // Staff Admission & Multi-Gate Scanning
        Route::prefix('staff')->middleware('runtime:legacy,node')->group(function () {
            Route::get('/events', [StaffAdmissionController::class, 'assignedEvents']);
            Route::get('/events/{event}/access-points', [StaffAdmissionController::class, 'accessPoints']);
            Route::post('/events/{event}/admissions/preview', [StaffAdmissionController::class, 'preview']);
            Route::post('/events/{event}/admissions', [StaffAdmissionController::class, 'scan']);
            Route::post('/events/{event}/walk-ins', [StaffAdmissionController::class, 'issueWalkIn'])->middleware('runtime:node');
        });
    });
});
