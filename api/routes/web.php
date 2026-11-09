<?php

use App\Http\Controllers\NodeOperatorConsoleController;
use App\Http\Controllers\NodeOperatorHandoffController;
use App\Http\Controllers\NodeOperatorStaffController;
use App\Http\Controllers\StudentEmailVerificationController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\GuestTicketController;
use App\Http\Controllers\Web\OrganizerAttendanceController;
use App\Http\Controllers\Web\OrganizerEventController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (config('ingress.mode') === 'node') {
        return redirect()->to(route('node.operator.login', absolute: false));
    }

    return redirect()->to(route('login', absolute: false));
});

Route::get('/student/complete', [StudentEmailVerificationController::class, 'show'])->middleware('runtime:cloud');
Route::post('/student/complete', [StudentEmailVerificationController::class, 'complete'])->middleware(['runtime:cloud', 'throttle:student-complete']);

Route::get('/login', [AuthController::class, 'showLoginForm'])->middleware('runtime:legacy,cloud')->name('login');
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('runtime:legacy,cloud')
    ->middleware('throttle:login');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('runtime:legacy,cloud')->name('logout');

Route::prefix('node/operator')->name('node.operator.')->middleware([
    'runtime:node',
    'node.operator.environment',
])->group(function (): void {
    Route::get('/login', [NodeOperatorConsoleController::class, 'showLogin'])->name('login');
    Route::post('/login', [NodeOperatorConsoleController::class, 'login'])
        ->middleware('throttle:node-operator-login')
        ->name('login.store');

    Route::middleware('node.operator.auth')->group(function (): void {
        Route::get('/', [NodeOperatorConsoleController::class, 'dashboard'])->name('dashboard');
        Route::post('/logout', [NodeOperatorConsoleController::class, 'logout'])->name('logout');

        Route::get('/staff', [NodeOperatorStaffController::class, 'index'])->name('staff.index');
        Route::post('/events/{event}/staff/{staff}/pairing', [NodeOperatorStaffController::class, 'issuePairingQr'])
            ->middleware('throttle:node-operator-actions')->name('staff.pairing.issue');
        Route::post('/events/{event}/staff/{staff}/pairing/cancel', [NodeOperatorStaffController::class, 'cancelPairing'])
            ->middleware('throttle:node-operator-actions')->name('staff.pairing.cancel');
        Route::post('/events/{event}/staff/{staff}/credential/provision', [NodeOperatorStaffController::class, 'provisionCredential'])
            ->middleware('throttle:node-operator-actions')->name('staff.credential.provision');
        Route::post('/events/{event}/staff/{staff}/credential/rotate', [NodeOperatorStaffController::class, 'rotateCredential'])
            ->middleware('throttle:node-operator-actions')->name('staff.credential.rotate');
        Route::post('/events/{event}/staff/{staff}/credential/revoke', [NodeOperatorStaffController::class, 'revokeCredential'])
            ->middleware('throttle:node-operator-actions')->name('staff.credential.revoke');

        Route::get('/handoff', [NodeOperatorHandoffController::class, 'index'])->name('handoff.index');
        Route::post('/handoff/snapshot', [NodeOperatorHandoffController::class, 'receiveSnapshot'])
            ->middleware('throttle:node-operator-actions')->name('handoff.snapshot.receive');
        Route::post('/handoff/events/{cloudEventId}/import', [NodeOperatorHandoffController::class, 'importSnapshot'])
            ->middleware('throttle:node-operator-actions')->name('handoff.snapshot.import');
        Route::post('/handoff/events/{cloudEventId}/authority', [NodeOperatorHandoffController::class, 'activateAuthority'])
            ->middleware('throttle:node-operator-actions')->name('handoff.authority.activate');
    });
});

Route::middleware(['auth', 'organizer', 'runtime:legacy,cloud'])->prefix('organizer')->name('organizer.')->group(function () {
    Route::get('/dashboard', [OrganizerEventController::class, 'dashboard'])->name('dashboard');

    Route::get('/events', [OrganizerEventController::class, 'index'])->name('events.index');
    Route::get('/events/create', [OrganizerEventController::class, 'create'])->name('events.create');
    Route::post('/events', [OrganizerEventController::class, 'store'])->name('events.store');
    Route::get('/events/{event}', [OrganizerEventController::class, 'show'])->name('events.show');
    Route::get('/events/{event}/edit', [OrganizerEventController::class, 'edit'])->name('events.edit');
    Route::put('/events/{event}', [OrganizerEventController::class, 'update'])->name('events.update');
    Route::post('/events/{event}/publish', [OrganizerEventController::class, 'publish'])->name('events.publish');
    Route::post('/events/{event}/snapshot/export', [OrganizerEventController::class, 'exportSnapshot'])->middleware('runtime:cloud')->name('events.snapshot.export');
    Route::post('/events/{event}/snapshot/receipt', [OrganizerEventController::class, 'confirmSnapshotReceipt'])->middleware('runtime:cloud')->name('events.snapshot.receipt');
    Route::post('/events/{event}/snapshot/import-receipt', [OrganizerEventController::class, 'confirmImportReceipt'])->middleware('runtime:cloud')->name('events.snapshot.import-receipt');
    Route::post('/events/{event}/authority-transfer', [OrganizerEventController::class, 'transferAuthority'])->middleware('runtime:cloud')->name('events.authority.transfer');
    Route::post('/events/{event}/discard-snapshot', [OrganizerEventController::class, 'discardSnapshot'])->middleware('runtime:cloud')->name('events.snapshot.discard');
    Route::post('/events/{event}/prepare-snapshot', [OrganizerEventController::class, 'prepareSnapshot'])->middleware('runtime:cloud')->name('events.snapshot.prepare');
    Route::post('/events/{event}/close-registration', [OrganizerEventController::class, 'closeRegistration'])->name('events.registration.close');
    Route::post('/events/{event}/cancel', [OrganizerEventController::class, 'cancel'])->name('events.cancel');
    Route::post('/events/{event}/guests', [GuestTicketController::class, 'store'])->middleware('runtime:cloud')->name('events.guests.store');
    Route::get('/events/{event}/guests/{registration}/pass', [GuestTicketController::class, 'show'])->middleware('runtime:cloud')->name('events.guest-pass');

    Route::post('/events/{event}/access-points', [OrganizerEventController::class, 'storeAccessPoint'])->name('events.access-points.store');
    Route::post('/events/{event}/staff', [OrganizerEventController::class, 'assignStaff'])->name('events.staff.assign');

    // Attendance view route (detailed in Phase 8)
    Route::get('/events/{event}/attendance', [OrganizerAttendanceController::class, 'show'])->name('attendance');
    Route::get('/events/{event}/attendance.csv', [OrganizerAttendanceController::class, 'export'])->name('attendance.export');
    Route::get('/events/{event}/attendance/feed', [OrganizerAttendanceController::class, 'feed'])->name('attendance.feed');

});
