<?php

use App\Http\Controllers\NodeOperatorConsoleController;
use App\Http\Controllers\NodeOperatorHandoffController;
use App\Http\Controllers\NodeOperatorStaffController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CheckoutController;
use App\Http\Controllers\Web\EventManagerController;
use App\Http\Controllers\Web\EventManagerInvitationController;
use App\Http\Controllers\Web\GuestTicketController;
use App\Http\Controllers\Web\GuestTicketRedemptionController;
use App\Http\Controllers\Web\OrganizerAttendanceController;
use App\Http\Controllers\Web\OrganizerEventController;
use App\Http\Controllers\Web\OrganizerVenueController;
use App\Http\Controllers\Web\PublicEventController;
use App\Http\Controllers\Web\PublicTicketController;
use App\Http\Controllers\Web\SimulatedPaymentAuthorizationController;
use App\Http\Controllers\Web\StudentAuthController;
use App\Http\Controllers\Web\TicketCategoryController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (config('ingress.mode') === 'node') {
        return redirect()->to(route('node.operator.login', absolute: false));
    }

    if (config('ingress.mode') === 'cloud') {
        return redirect()->route('public.events.index');
    }

    return redirect()->to(route('login', absolute: false));
});

Route::middleware('runtime:cloud')->group(function (): void {
    Route::get('/events', [PublicEventController::class, 'index'])->name('public.events.index');
    Route::get('/events/{event:slug}', [PublicEventController::class, 'show'])->name('public.events.show');
    Route::get('/events/{event:slug}/register', [PublicEventController::class, 'register'])->name('public.events.register');
    Route::post('/events/{event:slug}/register', [PublicEventController::class, 'store'])
        ->middleware('throttle:public-registration')->name('public.events.register.store');
    Route::get('/checkout/{checkoutOrder:public_id}', [CheckoutController::class, 'show'])
        ->name('public.checkout.show');
    Route::post('/checkout/{checkoutOrder:public_id}/attempt', [CheckoutController::class, 'beginAttempt'])
        ->middleware('throttle:public-registration')->name('public.checkout.attempt');
    Route::post('/checkout/{checkoutOrder:public_id}/process', [CheckoutController::class, 'process'])
        ->middleware('throttle:public-registration')->name('public.checkout.process');
    Route::post('/checkout/{checkoutOrder:public_id}/cancel', [CheckoutController::class, 'cancel'])
        ->middleware('throttle:public-registration')->name('public.checkout.cancel');
    Route::get('/tickets/{registration}/pass', [PublicTicketController::class, 'show'])
        ->whereNumber('registration')->middleware('throttle:public-ticket-pass')->name('public.tickets.show');
    Route::get('/tickets/{registration}/pdf', [PublicTicketController::class, 'download'])
        ->whereNumber('registration')->middleware('throttle:public-ticket-download')->name('public.tickets.pdf');
    Route::get('/tickets/redeem', [GuestTicketRedemptionController::class, 'show'])->name('public.tickets.redeem');
    Route::post('/tickets/redeem', [GuestTicketRedemptionController::class, 'store'])
        ->middleware('throttle:guest-ticket-redemption')->name('public.tickets.redeem.store');
    Route::post('/organizer/events/{event}/ticket-categories', [TicketCategoryController::class, 'store'])
        ->middleware(['auth', 'organizer'])->name('organizer.events.ticket-categories.store');
    Route::put('/organizer/events/{event}/ticket-categories/{ticketCategory}', [TicketCategoryController::class, 'update'])
        ->middleware(['auth', 'organizer'])->name('organizer.events.ticket-categories.update');
});

Route::get('/student/auth', [StudentAuthController::class, 'show'])->middleware('runtime:cloud')->name('student.auth');
Route::post('/student/session', [StudentAuthController::class, 'exchange'])
    ->middleware(['runtime:cloud', 'throttle:student-firebase-session'])
    ->name('student.session.exchange');
Route::middleware(['auth', 'runtime:cloud'])->group(function (): void {
    Route::get('/student/dashboard', [StudentAuthController::class, 'dashboard'])->name('student.dashboard');
    Route::post('/student/logout', [StudentAuthController::class, 'logout'])->name('student.logout');
});

Route::get('/event-manager-invitation', [EventManagerInvitationController::class, 'show'])
    ->middleware('runtime:cloud')->name('event-manager-invitation.show');
Route::post('/event-manager-invitations/preview', [EventManagerInvitationController::class, 'preview'])
    ->middleware(['runtime:cloud', 'throttle:manager-invitation'])->name('event-manager-invitations.preview');
Route::post('/event-manager-invitations/accept', [EventManagerInvitationController::class, 'accept'])
    ->middleware(['runtime:cloud', 'throttle:manager-invitation'])->name('event-manager-invitations.accept');

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
    Route::get('/setup', [NodeOperatorConsoleController::class, 'showSetup'])->name('setup');
    Route::post('/setup', [NodeOperatorConsoleController::class, 'createFirstOperator'])
        ->middleware('throttle:node-operator-login')->name('setup.store');
    Route::post('/login', [NodeOperatorConsoleController::class, 'login'])
        ->middleware('throttle:node-operator-login')
        ->name('login.store');

    Route::middleware('node.operator.auth')->group(function (): void {
        Route::get('/', [NodeOperatorConsoleController::class, 'dashboard'])->name('dashboard');
        Route::post('/events/{event}/sync', [NodeOperatorConsoleController::class, 'syncEvent'])
            ->whereNumber('event')->middleware('throttle:node-operator-actions')->name('events.sync');
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

    Route::get('/venues', [OrganizerVenueController::class, 'index'])->middleware('runtime:cloud')->name('venues.index');
    Route::post('/venues', [OrganizerVenueController::class, 'store'])->middleware('runtime:cloud')->name('venues.store');
    Route::put('/venues/{venue}', [OrganizerVenueController::class, 'update'])->middleware('runtime:cloud')->name('venues.update');

    Route::get('/events', [OrganizerEventController::class, 'index'])->name('events.index');
    Route::get('/events/create', [OrganizerEventController::class, 'create'])->name('events.create');
    Route::post('/events', [OrganizerEventController::class, 'store'])->name('events.store');
    Route::get('/events/{event}', [OrganizerEventController::class, 'show'])->name('events.show');
    Route::post('/events/{event}/managers', [EventManagerController::class, 'store'])
        ->middleware(['runtime:cloud', 'throttle:manager-invitation'])->name('events.managers.store');
    Route::delete('/events/{event}/managers/{manager}', [EventManagerController::class, 'revoke'])
        ->middleware('runtime:cloud')->name('events.managers.revoke');
    Route::get('/events/{event}/edit', [OrganizerEventController::class, 'edit'])->name('events.edit');
    Route::put('/events/{event}', [OrganizerEventController::class, 'update'])->name('events.update');
    Route::post('/events/{event}/publish', [OrganizerEventController::class, 'publish'])->name('events.publish');
    Route::post('/events/{event}/snapshot/export', [OrganizerEventController::class, 'exportSnapshot'])->middleware('runtime:cloud')->name('events.snapshot.export');
    Route::post('/events/{event}/snapshot/receipt', [OrganizerEventController::class, 'confirmSnapshotReceipt'])->middleware('runtime:cloud')->name('events.snapshot.receipt');
    Route::post('/events/{event}/snapshot/import-receipt', [OrganizerEventController::class, 'confirmImportReceipt'])->middleware('runtime:cloud')->name('events.snapshot.import-receipt');
    Route::post('/events/{event}/authority-transfer', [OrganizerEventController::class, 'transferAuthority'])->middleware('runtime:cloud')->name('events.authority.transfer');
    Route::post('/events/{event}/discard-snapshot', [OrganizerEventController::class, 'discardSnapshot'])->middleware('runtime:cloud')->name('events.snapshot.discard');
    Route::post('/events/{event}/prepare-snapshot', [OrganizerEventController::class, 'prepareSnapshot'])->middleware('runtime:cloud')->name('events.snapshot.prepare');
    Route::post('/events/{event}/simulated-payments', [SimulatedPaymentAuthorizationController::class, 'update'])
        ->middleware('runtime:cloud')->name('events.simulated-payments.update');
    Route::post('/events/{event}/close-registration', [OrganizerEventController::class, 'closeRegistration'])->name('events.registration.close');
    Route::post('/events/{event}/cancel', [OrganizerEventController::class, 'cancel'])->name('events.cancel');
    Route::post('/events/{event}/guests', [GuestTicketController::class, 'store'])->middleware('runtime:cloud')->name('events.guests.store');
    Route::get('/events/{event}/guests/{registration}/pass', [GuestTicketController::class, 'show'])->middleware('runtime:cloud')->name('events.guest-pass');
    Route::post('/events/{event}/guests/{registration}/redemption-code', [GuestTicketController::class, 'recover'])
        ->middleware(['runtime:cloud', 'throttle:guest-ticket-redemption'])->name('events.guests.recover');

    Route::post('/events/{event}/access-points', [OrganizerEventController::class, 'storeAccessPoint'])->name('events.access-points.store');
    Route::put('/events/{event}/access-points/{accessPoint}', [OrganizerEventController::class, 'updateAccessPoint'])->name('events.access-points.update');
    Route::post('/events/{event}/staff', [OrganizerEventController::class, 'assignStaff'])->name('events.staff.assign');
    Route::delete('/events/{event}/staff/{staff}', [OrganizerEventController::class, 'revokeStaff'])->name('events.staff.revoke');

    // Attendance view route (detailed in Phase 8)
    Route::get('/events/{event}/attendance', [OrganizerAttendanceController::class, 'show'])->name('attendance');
    Route::get('/events/{event}/attendance.csv', [OrganizerAttendanceController::class, 'export'])->name('attendance.export');
    Route::get('/events/{event}/attendance/feed', [OrganizerAttendanceController::class, 'feed'])->name('attendance.feed');

});
