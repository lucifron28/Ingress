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

use App\Actions\AdmitTicket;
use App\Actions\RegisterForEvent;
use App\Http\Middleware\EnsureRuntimeDatabase;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$payload = json_decode(base64_decode($argv[2] ?? ''), true) ?: [];

// Point this worker at the dedicated concurrency database.
config(['database.connections.pgsql.database' => $payload['db']]);
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
    } elseif ($mode === 'runtime') {
        config(['ingress.mode' => $payload['runtime'], 'ingress.node_id' => $payload['node_id'] ?? null]);
        try {
            $response = (new EnsureRuntimeDatabase)->handle(
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
