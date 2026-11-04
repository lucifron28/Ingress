<?php

/**
 * Child worker for real PostgreSQL concurrency verification.
 *
 * Boots Laravel against the real PostgreSQL server, signals READY on stdout,
 * then blocks on stdin until the parent process writes "GO". Both children are
 * released simultaneously, so the database transactions genuinely overlap.
 *
 * Usage: php scripts/pgsql_concurrency_child.php <mode> <base64-json-payload>
 */

use App\Actions\ActivateEventAuthority;
use App\Actions\AdmitTicket;
use App\Actions\ExportSnapshot;
use App\Actions\ImportReceivedSnapshot;
use App\Actions\IssueGuestTicket;
use App\Actions\IssueImportReceipt;
use App\Actions\IssueNodeWalkIn;
use App\Actions\NodeStaffAccess;
use App\Actions\NodeStaffPairing;
use App\Actions\PrepareAdmissionBatch;
use App\Actions\PrepareEventSnapshot;
use App\Actions\ReceiveAdmissionBatch;
use App\Actions\ReceiveSnapshot;
use App\Actions\ReceiveWalkIn;
use App\Actions\ReceiveWalkInRecovery;
use App\Actions\RegisterForEvent;
use App\Actions\SyncWalkInRecovery;
use App\Actions\TransferEventAuthority;
use App\Http\Controllers\Api\V1\NodeStaffAuthController;
use App\Http\Controllers\StudentEmailVerificationController;
use App\Http\Middleware\EnsureRuntimeDatabase;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$payload = json_decode(base64_decode($argv[2] ?? ''), true) ?: [];

// Point this worker at the dedicated concurrency database.
config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.url' => null,
    'database.connections.pgsql.database' => $payload['db'],
]);
DB::purge('pgsql');

// Open the PostgreSQL connection (full handshake) before announcing readiness, so
// the only work left after the barrier is the contested transaction. Otherwise the
// connection setup cost would let one worker finish before the other even connects,
// silently degrading the race into a sequential run that still passes.
DB::select('select 1');

// Observe whether this worker reached the admission insert at all. A losing race
// can be decided either by the pre-check (the winner's row was already committed)
// or by the unique index on admissions.client_scan_id; reporting the branch keeps
// the harness honest about which path it exercised.
//
// beforeExecuting records the insert before PostgreSQL can block or reject it.
$attemptedAdmissionInsert = false;

DB::beforeExecuting(function (string $query) use (&$attemptedAdmissionInsert): void {
    if (str_contains(strtolower($query), 'insert into "admissions"')) {
        $attemptedAdmissionInsert = true;
    }
});

$emit = function (array $result): void {
    fwrite(STDOUT, 'RESULT:'.json_encode($result).PHP_EOL);
    fflush(STDOUT);
};

// --- Barrier: announce readiness, wait for the parent's GO signal -------------
fwrite(STDOUT, 'READY'.PHP_EOL);
fflush(STDOUT);

$signal = fgets(STDIN);

if (trim((string) $signal) !== 'GO') {
    $emit(['ok' => false, 'reason' => 'barrier_not_released']);
    exit(1);
}

// --- Execute the contested operation -----------------------------------------
try {
    if ($mode === 'seat') {
        $result = (new RegisterForEvent)->execute(
            User::find($payload['user_id']),
            $payload['event_id']
        );

        $emit([
            'ok' => true,
            'registration_id' => $result['registration']->id,
        ]);
    } elseif ($mode === 'scan') {
        $result = (new AdmitTicket)->execute(
            staff: User::find($payload['staff_user_id']),
            eventId: $payload['event_id'],
            accessPointId: $payload['access_point_id'],
            qrPayload: $payload['qr_payload'],
            clientScanId: $payload['client_scan_id']
        );

        $emit([
            'ok' => $result['http_code'] === 200,
            'http_code' => $result['http_code'],
            'status' => $result['status'],
            'attempted_admission_insert' => $attemptedAdmissionInsert,
        ]);
    } elseif ($mode === 'guest_registration') {
        config(['ingress.mode' => 'cloud']);
        $registration = app(IssueGuestTicket::class)->execute($payload['event_id'], 'Race Guest', $payload['email'], 'guest');
        $emit(['ok' => true, 'registration_id' => $registration->id]);
    } elseif ($mode === 'node_walk_in') {
        config(['ingress.mode' => 'node', 'ingress.node_id' => $payload['node_id'], 'ingress.authority_public_key' => $payload['public_key']]);
        $staff = User::findOrFail($payload['staff_user_id']);
        $staff->withAccessToken(PersonalAccessToken::findOrFail($payload['token_id']));
        $ticket = app(IssueNodeWalkIn::class)->execute($staff, $payload['event_id'], $payload['request_id'], 'Walk-in Race', $payload['email'], 'guest', null);
        $emit(['ok' => true, 'ticket_id' => $ticket->id, 'qr_hash' => $ticket->qr_hash]);
    } elseif ($mode === 'receive_walk_in') {
        config(['ingress.mode' => 'cloud']);
        $result = app(ReceiveWalkIn::class)->execute($payload['node_id'], $payload['package']);
        $emit(['ok' => true, 'receipt' => $result['receipt'], 'registration_id' => $result['registration_id'], 'ticket_id' => $result['ticket_id']]);
    } elseif ($mode === 'prepare_walk_in_recovery') {
        config(['ingress.mode' => 'node', 'ingress.node_id' => $payload['node_id'], 'ingress.node_sync_key' => $payload['secret'], 'ingress.authority_public_key' => $payload['public_key']]);
        $recovery = app(SyncWalkInRecovery::class)->prepare($payload['event_id']);
        $emit(['ok' => $recovery !== null, 'recovery_id' => $recovery['recovery_id'] ?? null,
            'package_hash' => isset($recovery['package']) ? hash('sha256', $recovery['package']) : null]);
    } elseif ($mode === 'receive_walk_in_recovery') {
        config(['ingress.mode' => 'cloud']);
        $result = app(ReceiveWalkInRecovery::class)->execute($payload['node_id'], $payload['package']);
        $emit(['ok' => true, 'receipt' => $result['receipt'], 'recovery_id' => $result['recovery_id']]);
    } elseif ($mode === 'redeem_pairing') {
        config(['ingress.mode' => 'node', 'ingress.node_id' => $payload['node_id']]);
        $session = app(NodeStaffPairing::class)->redeem($payload['token'], $payload['node_id'], 'race-scanner');
        $emit(['ok' => ! empty($session['token'])]);
    } elseif ($mode === 'prepare_admissions') {
        config(['ingress.mode' => 'node', 'ingress.node_id' => $payload['node_id'], 'ingress.node_sync_key' => $payload['secret'], 'ingress.authority_public_key' => $payload['public_key']]);
        $batch = app(PrepareAdmissionBatch::class)->execute($payload['event_id']);
        $emit(['ok' => $batch !== null, 'batch_id' => $batch['batch_id'] ?? null, 'package_hash' => isset($batch['package']) ? hash('sha256', $batch['package']) : null]);
    } elseif ($mode === 'receive_admissions') {
        config(['ingress.mode' => 'cloud']);
        $result = app(ReceiveAdmissionBatch::class)->execute($payload['node_id'], $payload['package']);
        $emit(['ok' => true, 'receipt' => $result['receipt']]);
    } elseif ($mode === 'node_scan') {
        config(['ingress.mode' => 'node', 'ingress.node_id' => $payload['node_id'], 'ingress.authority_public_key' => $payload['public_key']]);
        $staff = User::findOrFail($payload['staff_user_id']);
        $staff->withAccessToken(PersonalAccessToken::findOrFail($payload['token_id']));
        $result = app(AdmitTicket::class)->execute($staff, $payload['event_id'], $payload['access_point_id'],
            $payload['qr_payload'], $payload['client_scan_id'], $payload['direction'] ?? 'entry');
        $emit(['ok' => $result['http_code'] === 200, 'http_code' => $result['http_code'], 'status' => $result['status']]);
    } elseif ($mode === 'register_node') {
        config(['ingress.mode' => 'cloud']);
        $exitCode = Artisan::call('ingress:register-node', ['node_id' => $payload['node_id']]);
        $output = Artisan::output();
        $emit(['ok' => $exitCode === 0, 'key_shown' => str_contains($output, 'INGRESS_NODE_SYNC_KEY=')]);
    } elseif ($mode === 'export') {
        config(['ingress.mode' => 'cloud']);
        $sealed = app(ExportSnapshot::class)->execute($payload['event_id'], $payload['node_id']);
        $emit(['ok' => true, 'sealed' => $sealed !== '']);
    } elseif ($mode === 'receive') {
        config(['ingress.mode' => 'node', 'ingress.node_id' => $payload['node_id'], 'ingress.node_sync_key' => $payload['secret']]);
        $receipt = app(ReceiveSnapshot::class)->execute($payload['sealed']);
        $emit(['ok' => true, 'receipt_hash' => hash('sha256', $receipt)]);
    } elseif ($mode === 'import') {
        config(['ingress.mode' => 'node', 'ingress.node_id' => $payload['node_id']]);
        $eventId = app(ImportReceivedSnapshot::class)->execute($payload['event_id']);
        $emit(['ok' => true, 'event_id' => $eventId]);
    } elseif ($mode === 'transfer_authority') {
        config(['ingress.mode' => 'cloud', 'ingress.authority_private_key' => $payload['private_key'], 'ingress.authority_public_key' => $payload['public_key']]);
        $grant = app(TransferEventAuthority::class)->execute($payload['event_id']);
        $emit(['ok' => true, 'grant_hash' => hash('sha256', $grant)]);
    } elseif ($mode === 'activate_authority') {
        config(['ingress.mode' => 'node', 'ingress.node_id' => $payload['node_id'], 'ingress.authority_public_key' => $payload['public_key']]);
        $eventId = app(ActivateEventAuthority::class)->execute($payload['grant']);
        $emit(['ok' => true, 'event_id' => $eventId]);
    } elseif ($mode === 'import_receipt') {
        config(['ingress.mode' => 'node', 'ingress.node_id' => $payload['node_id'], 'ingress.node_sync_key' => $payload['secret']]);
        $receipt = app(IssueImportReceipt::class)->execute($payload['event_id']);
        $emit(['ok' => true, 'receipt_hash' => hash('sha256', $receipt)]);
    } elseif ($mode === 'snapshot') {
        config(['ingress.mode' => 'cloud']);
        $snapshot = app(PrepareEventSnapshot::class)->execute($payload['event_id']);
        $emit(['ok' => true, 'id' => $snapshot['id'], 'version' => $snapshot['version']]);
    } elseif ($mode === 'node_staff_login') {
        $app->detectEnvironment(fn () => 'testing');
        config(['ingress.mode' => 'node', 'ingress.node_id' => $payload['node_id']]);
        $request = Request::create('/api/v1/auth/node-staff/login', 'POST', ['email' => $payload['email'], 'password' => $payload['password']]);
        $response = app(NodeStaffAuthController::class)->login($request, app(NodeStaffAccess::class));
        $emit(['ok' => $response->getStatusCode() === 200, 'http_code' => $response->getStatusCode()]);
    } elseif ($mode === 'student_complete') {
        config(['ingress.mode' => 'cloud']);
        $request = Request::create('/student/complete', 'POST', [
            'token' => $payload['token'], 'name' => 'Race Student', 'student_id' => $payload['student_id'],
            'password' => 'long-race-password', 'password_confirmation' => 'long-race-password',
        ]);
        $response = app(StudentEmailVerificationController::class)->complete($request);
        $emit(['ok' => $response->getStatusCode() === 200, 'http_code' => $response->getStatusCode()]);
    } elseif ($mode === 'runtime') {
        $app->detectEnvironment(fn () => 'production');
        config(['ingress.mode' => $payload['runtime'], 'ingress.node_id' => $payload['node_id'] ?? null]);
        try {
            $response = app(EnsureRuntimeDatabase::class)->handle(
                Request::create('/login'),
                fn () => response('ok')
            );
            $emit(['http_code' => $response->getStatusCode()]);
        } catch (HttpException $e) {
            $emit(['http_code' => $e->getStatusCode()]);
        }
    } else {
        $emit(['ok' => false, 'reason' => 'unknown_mode']);
        exit(1);
    }
} catch (ValidationException $e) {
    $emit([
        'ok' => false,
        'reason' => 'validation_error',
        'detail' => implode(' ', Arr::flatten($e->errors())),
    ]);
} catch (Throwable $e) {
    $emit([
        'ok' => false,
        'reason' => 'exception',
        'detail' => $e::class.': '.$e->getMessage(),
    ]);
}
