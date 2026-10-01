<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/login', [\App\Http\Controllers\Web\AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [\App\Http\Controllers\Web\AuthController::class, 'login'])
    ->middleware('throttle:login');
Route::post('/logout', [\App\Http\Controllers\Web\AuthController::class, 'logout'])->name('logout');

Route::middleware(['auth', 'organizer'])->prefix('organizer')->name('organizer.')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Web\OrganizerEventController::class, 'dashboard'])->name('dashboard');
    
    Route::get('/events', [\App\Http\Controllers\Web\OrganizerEventController::class, 'index'])->name('events.index');
    Route::get('/events/create', [\App\Http\Controllers\Web\OrganizerEventController::class, 'create'])->name('events.create');
    Route::post('/events', [\App\Http\Controllers\Web\OrganizerEventController::class, 'store'])->name('events.store');
    Route::get('/events/{event}', [\App\Http\Controllers\Web\OrganizerEventController::class, 'show'])->name('events.show');
    Route::get('/events/{event}/edit', [\App\Http\Controllers\Web\OrganizerEventController::class, 'edit'])->name('events.edit');
    Route::put('/events/{event}', [\App\Http\Controllers\Web\OrganizerEventController::class, 'update'])->name('events.update');
    Route::post('/events/{event}/publish', [\App\Http\Controllers\Web\OrganizerEventController::class, 'publish'])->name('events.publish');
    Route::post('/events/{event}/cancel', [\App\Http\Controllers\Web\OrganizerEventController::class, 'cancel'])->name('events.cancel');
    
    Route::post('/events/{event}/access-points', [\App\Http\Controllers\Web\OrganizerEventController::class, 'storeAccessPoint'])->name('events.access-points.store');
    Route::post('/events/{event}/staff', [\App\Http\Controllers\Web\OrganizerEventController::class, 'assignStaff'])->name('events.staff.assign');

    // Attendance view route (detailed in Phase 8)
    Route::get('/events/{event}/attendance', [\App\Http\Controllers\Web\OrganizerAttendanceController::class, 'show'])->name('attendance');
    Route::get('/events/{event}/attendance/feed', [\App\Http\Controllers\Web\OrganizerAttendanceController::class, 'feed'])->name('attendance.feed');
});
