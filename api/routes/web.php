<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\OrganizerAttendanceController;
use App\Http\Controllers\Web\OrganizerEventController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(['auth', 'organizer', 'runtime:legacy,cloud'])->prefix('organizer')->name('organizer.')->group(function () {
    Route::get('/dashboard', [OrganizerEventController::class, 'dashboard'])->name('dashboard');

    Route::get('/events', [OrganizerEventController::class, 'index'])->name('events.index');
    Route::get('/events/create', [OrganizerEventController::class, 'create'])->name('events.create');
    Route::post('/events', [OrganizerEventController::class, 'store'])->name('events.store');
    Route::get('/events/{event}', [OrganizerEventController::class, 'show'])->name('events.show');
    Route::get('/events/{event}/edit', [OrganizerEventController::class, 'edit'])->name('events.edit');
    Route::put('/events/{event}', [OrganizerEventController::class, 'update'])->name('events.update');
    Route::post('/events/{event}/publish', [OrganizerEventController::class, 'publish'])->name('events.publish');
    Route::post('/events/{event}/close-registration', [OrganizerEventController::class, 'closeRegistration'])->name('events.registration.close');
    Route::post('/events/{event}/cancel', [OrganizerEventController::class, 'cancel'])->name('events.cancel');

    Route::post('/events/{event}/access-points', [OrganizerEventController::class, 'storeAccessPoint'])->name('events.access-points.store');
    Route::post('/events/{event}/staff', [OrganizerEventController::class, 'assignStaff'])->name('events.staff.assign');

    // Attendance view route (detailed in Phase 8)
    Route::get('/events/{event}/attendance', [OrganizerAttendanceController::class, 'show'])->name('attendance');
    Route::get('/events/{event}/attendance/feed', [OrganizerAttendanceController::class, 'feed'])->name('attendance.feed');

});
