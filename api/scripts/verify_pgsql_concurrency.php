<?php

/**
 * Real PostgreSQL concurrency verification for Ingress.
 *
 * Proves the two critical concurrency guarantees against a real PostgreSQL
 * server (not SQLite, which serializes writers and would give false confidence):
 *
 *   Case A — Final seat race: two users register simultaneously for an event
 *            with capacity 1. Exactly one confirmed registration must exist.
 *
 *   Case B — Same-ticket race: two gate scanners submit the same ticket at the
 *            same moment. Exactly one admission must exist.
 *
 *   Case C — Guest-ticket race: two requests redeem the same private code at
 *            the same moment. Exactly one credential rotation must commit.
 *
 * Both cases release two child PHP processes through a two-way barrier so the
 * database transactions genuinely overlap.
 *
 * Usage (from the api/ directory):
 *   php scripts/verify_pgsql_concurrency.php
 *
 * The script provisions a dedicated "ingress_concurrency" database so it never
 * touches demonstration data.
 */

use App\Actions\ActivateEventAuthority;
use App\Actions\AdmissionSyncEnvelope;
use App\Actions\AuthorityGrant;
use App\Actions\ImportReceivedSnapshot;
use App\Actions\ManageNodeStaffCredential;
use App\Actions\NodeStaffPairing;
use App\Actions\PrepareEventSnapshot;
use App\Actions\ReceiveSnapshot;
use App\Actions\RecoverNodeWalkIn;
use App\Actions\RegisterAttendeeForEvent;
use App\Actions\RegisterForEvent;
use App\Actions\SnapshotEnvelope;
use App\Actions\SyncWalkInRecovery;
use App\Http\Controllers\Api\V1\TicketTransferController;
use App\Models\AccessPoint;
use App\Models\Admission;
use App\Models\CheckoutOrder;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\TicketTransfer;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

try {
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $error) {
    fwrite(STDERR, 'POSTGRESQL CONCURRENCY VERIFICATION FAILED: '.get_class($error).PHP_EOL);
    exit(1);
}

set_exception_handler(static function (Throwable $error): void {
    $previous = $error;
    while ($previous->getPrevious() !== null) {
        $previous = $previous->getPrevious();
    }

    $constraint = '';
    if ($previous instanceof PDOException) {
        $message = (string) ($previous->errorInfo[2] ?? '');
        if (preg_match('/unique constraint "([^"]+)"/i', $message, $matches) === 1) {
            $constraint = ' (constraint '.$matches[1].')';
        }
    }

    fwrite(STDERR, 'POSTGRESQL CONCURRENCY VERIFICATION FAILED: '.get_class($error).$constraint.PHP_EOL);
    exit(1);
});

$testDatabase = getenv('INGRESS_CONCURRENCY_DB') ?: 'ingress_concurrency';
$childScript = __DIR__.'/pgsql_concurrency_child.php';

$failures = [];

$assert = function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? '  [PASS] ' : '  [FAIL] ').$label.PHP_EOL;
    if (! $condition) {
        $failures[] = $label;
    }
};

$baseConnection = config('database.connections.pgsql');

echo "Provisioning database '{$testDatabase}' on {$baseConnection['host']}:{$baseConnection['port']}".PHP_EOL;

$adminDsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=postgres',
    $baseConnection['host'],
    $baseConnection['port']
);

$admin = new PDO($adminDsn, $baseConnection['username'], $baseConnection['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$existsQuery = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
$existsQuery->execute([$testDatabase]);
$exists = $existsQuery->fetchColumn();

if ($exists) {
    fwrite(STDERR, "Refusing to reset existing database '{$testDatabase}'. Choose an unused INGRESS_CONCURRENCY_DB.\n");
    exit(1);
}

if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $testDatabase)) {
    fwrite(STDERR, "Invalid INGRESS_CONCURRENCY_DB name.\n");
    exit(1);
}

$admin->exec(sprintf('CREATE DATABASE "%s"', $testDatabase));
echo "  created.\n";

config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.url' => null,
    'database.connections.pgsql.database' => $testDatabase,
]);
DB::purge('pgsql');

if (Artisan::call('migrate:fresh', ['--force' => true]) !== 0) {
    fwrite(STDERR, "POSTGRESQL CONCURRENCY VERIFICATION FAILED: migration failed.\n");
    exit(1);
}
echo "  schema migrated.\n\n";

$suffix = Str::lower(Str::random(6));

$venue = Venue::create([
    'name' => 'Concurrency Test Venue',
    'code' => 'CC-'.strtoupper($suffix),
    'location' => 'Test Harness',
    'default_capacity' => 100,
]);

$organizer = User::create([
    'name' => 'Harness Organizer',
    'email' => "harness.organizer.{$suffix}@mseuf.edu.ph",
    'role' => User::ROLE_ORGANIZER,
    'password' => Hash::make('password'),
]);

/**
 * Spawn two children, release them simultaneously, and collect their results.
 *
 * @param  array<int, array{0: string, 1: array<string, mixed>}>  $jobs
 * @return array<int, array<string, mixed>>
 */
$runConcurrently = function (array $jobs, string $childScript, string $testDatabase): array {
    $processes = [];
    $pipes = [];

    foreach ($jobs as $index => [$mode, $payload]) {
        $payload['db'] = $testDatabase;

        $command = [
            PHP_BINARY,
            $childScript,
            $mode,
            base64_encode(json_encode($payload)),
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $childPipes, dirname($childScript, 2), null, [
            'bypass_shell' => true,
        ]);

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to spawn concurrency child process.');
        }

        stream_set_blocking($childPipes[1], true);
        stream_set_blocking($childPipes[2], true);

        $processes[$index] = $process;
        $pipes[$index] = $childPipes;
    }

    $ready = [];
    foreach ($pipes as $index => $childPipes) {
        $line = fgets($childPipes[1]);
        $ready[$index] = trim((string) $line) === 'READY';
    }

    if (in_array(false, $ready, true)) {
        foreach ($processes as $process) {
            proc_terminate($process);
        }
        throw new RuntimeException('A child process failed to reach the barrier.');
    }

    foreach ($pipes as $childPipes) {
        fwrite($childPipes[0], 'GO'.PHP_EOL);
        fflush($childPipes[0]);
    }

    $results = [];
    foreach ($pipes as $index => $childPipes) {
        $stdout = stream_get_contents($childPipes[1]);
        $stderr = stream_get_contents($childPipes[2]);

        fclose($childPipes[0]);
        fclose($childPipes[1]);
        fclose($childPipes[2]);
        proc_close($processes[$index]);

        $result = null;
        foreach (explode(PHP_EOL, (string) $stdout) as $line) {
            if (str_starts_with($line, 'RESULT:')) {
                $result = json_decode(substr($line, 7), true);
            }
        }

        $results[$index] = $result ?? [
            'ok' => false,
            'reason' => 'no_result',
            'detail' => trim((string) $stdout.' '.(string) $stderr),
        ];
    }

    return $results;
};

// =============================================================================
// CASE A — Two users compete for the final seat (capacity = 1)
// =============================================================================
echo "CASE A: two simultaneous registrations for the final seat (capacity = 1)\n";

$seatEvent = Event::create([
    'organizer_id' => $organizer->id,
    'venue_id' => $venue->id,
    'title' => 'Final Seat Race',
    'slug' => "final-seat-race-{$suffix}",
    'capacity' => 1,
    'registration_opens_at' => now()->subHour(),
    'registration_closes_at' => now()->addDay(),
    'starts_at' => now()->addDays(2),
    'ends_at' => now()->addDays(2)->addHours(3),
    'status' => Event::STATUS_PUBLISHED,
]);

$seatUsers = collect([1, 2])->map(fn (int $n) => User::create([
    'name' => "Final Seat Student {$n}",
    'email' => "final.seat.{$n}.{$suffix}@mseuf.edu.ph",
    'student_id' => "CC-{$suffix}-{$n}",
    'role' => User::ROLE_STUDENT,
    'password' => Hash::make('password'),
]));

$seatResults = $runConcurrently([
    ['seat', ['user_id' => $seatUsers[0]->id, 'event_id' => $seatEvent->id]],
    ['seat', ['user_id' => $seatUsers[1]->id, 'event_id' => $seatEvent->id]],
], $childScript, $testDatabase);

foreach ($seatResults as $index => $result) {
    echo '  worker '.($index + 1).': '.json_encode($result).PHP_EOL;
}

$seatConfirmed = Registration::where('event_id', $seatEvent->id)
    ->where('status', Registration::STATUS_CONFIRMED)
    ->count();

$seatSuccesses = count(array_filter($seatResults, fn ($r) => ($r['ok'] ?? false) === true));

echo "  confirmed registrations in database: {$seatConfirmed}\n";
$assert($seatSuccesses === 1, 'exactly one child reported a successful registration');
$assert($seatConfirmed === 1, 'exactly one confirmed registration exists (capacity invariant holds)');
echo "\n";

// CASE A2 — A paid reservation and free registration compete for the same final seat.
echo "CASE A2: paid reservation and free registration compete for the final seat\n";

$mixedSeatEvent = Event::create([
    'organizer_id' => $organizer->id,
    'venue_id' => $venue->id,
    'title' => 'Paid and Free Seat Race',
    'slug' => "paid-free-seat-race-{$suffix}",
    'capacity' => 1,
    'registration_opens_at' => now()->subHour(),
    'registration_closes_at' => now()->addDay(),
    'starts_at' => now()->addDays(2),
    'ends_at' => now()->addDays(2)->addHours(3),
    'status' => Event::STATUS_PUBLISHED,
    'simulated_payments_enabled' => true,
]);
$paidCategory = $mixedSeatEvent->ticketCategories()->create([
    'name' => 'Paid Admission',
    'price_minor' => 12500,
    'currency' => 'PHP',
    'capacity' => 1,
    'eligibility' => 'public',
    'is_active' => true,
    'is_default' => false,
]);
$mixedSeatStudent = User::create([
    'name' => "Mixed Race Student {$suffix}",
    'email' => "mixed.race.{$suffix}@mseuf.edu.ph",
    'student_id' => "MIX-{$suffix}",
    'role' => User::ROLE_STUDENT,
    'password' => Hash::make('password'),
]);

$mixedSeatResults = $runConcurrently([
    ['seat', ['user_id' => $mixedSeatStudent->id, 'event_id' => $mixedSeatEvent->id]],
    ['paid_checkout_start', [
        'event_id' => $mixedSeatEvent->id,
        'category_id' => $paidCategory->id,
        'guest_name' => 'Paid Race Guest',
        'email' => "paid.race.{$suffix}@example.test",
        'idempotency_key' => (string) Str::uuid(),
    ]],
], $childScript, $testDatabase);

foreach ($mixedSeatResults as $index => $result) {
    echo '  worker '.($index + 1).': '.json_encode($result).PHP_EOL;
}

$mixedSeatConfirmed = Registration::query()->where('event_id', $mixedSeatEvent->id)
    ->where('status', Registration::STATUS_CONFIRMED)->count();
$mixedSeatReserved = CheckoutOrder::query()->where('event_id', $mixedSeatEvent->id)->activeReservation()->count();
$mixedSeatSuccesses = count(array_filter($mixedSeatResults, fn ($result) => ($result['ok'] ?? false) === true));
$mixedSeatTickets = Ticket::query()->whereHas('registration', fn ($query) => $query->where('event_id', $mixedSeatEvent->id))->count();

echo "  confirmed registrations: {$mixedSeatConfirmed}; active paid reservations: {$mixedSeatReserved}\n";
$assert($mixedSeatSuccesses === 1, 'only one payment reservation or free registration won');
$assert($mixedSeatConfirmed + $mixedSeatReserved === 1, 'confirmed registrations plus active reservations stay within capacity');
$assert($mixedSeatTickets === $mixedSeatConfirmed, 'pending paid reservations issue no ticket');
echo "\n";

// =============================================================================
// CASE B — Two gates scan the same unused ticket simultaneously
// =============================================================================
echo "CASE B: two gates scan the same unused ticket simultaneously\n";

$scanEvent = Event::create([
    'organizer_id' => $organizer->id,
    'venue_id' => $venue->id,
    'title' => 'Same Ticket Race',
    'slug' => "same-ticket-race-{$suffix}",
    'capacity' => 50,
    'registration_opens_at' => now()->subHour(),
    'registration_closes_at' => now()->addDay(),
    'starts_at' => now()->addDays(2),
    'ends_at' => now()->addDays(2)->addHours(3),
    'status' => Event::STATUS_PUBLISHED,
]);

$gateA = AccessPoint::create([
    'event_id' => $scanEvent->id,
    'name' => 'Race Gate A',
    'code' => 'RACE-A',
    'is_active' => true,
]);

$gateB = AccessPoint::create([
    'event_id' => $scanEvent->id,
    'name' => 'Race Gate B',
    'code' => 'RACE-B',
    'is_active' => true,
]);

$scanStaff = collect([['A', $gateA], ['B', $gateB]])->mapWithKeys(function (array $pair) use ($scanEvent, $suffix) {
    [$label, $gate] = $pair;

    $user = User::create([
        'name' => "Race Staff {$label}",
        'email' => "race.staff.{$label}.{$suffix}@mseuf.edu.ph",
        'role' => User::ROLE_STAFF,
        'password' => Hash::make('password'),
    ]);

    EventStaff::create([
        'event_id' => $scanEvent->id,
        'user_id' => $user->id,
        'access_point_id' => $gate->id,
    ]);

    return [$label => $user->id];
});

$scanStudent = User::create([
    'name' => 'Race Attendee',
    'email' => "race.attendee.{$suffix}@mseuf.edu.ph",
    'student_id' => "RACE-{$suffix}",
    'role' => User::ROLE_STUDENT,
    'password' => Hash::make('password'),
]);

$issued = (new RegisterForEvent)->execute($scanStudent, $scanEvent->id);
$qrPayload = $issued['qr_payload'];

$scanResults = $runConcurrently([
    ['scan', [
        'staff_user_id' => $scanStaff['A'],
        'event_id' => $scanEvent->id,
        'access_point_id' => $gateA->id,
        'qr_payload' => $qrPayload,
        'client_scan_id' => (string) Str::uuid(),
    ]],
    ['scan', [
        'staff_user_id' => $scanStaff['B'],
        'event_id' => $scanEvent->id,
        'access_point_id' => $gateB->id,
        'qr_payload' => $qrPayload,
        'client_scan_id' => (string) Str::uuid(),
    ]],
], $childScript, $testDatabase);

foreach ($scanResults as $index => $result) {
    echo '  worker '.($index + 1).': '.json_encode($result).PHP_EOL;
}

$admissionCount = Admission::where('ticket_id', $issued['ticket']->id)->count();
$scanSuccesses = count(array_filter($scanResults, fn ($r) => ($r['ok'] ?? false) === true));
$alreadyAdmitted = count(array_filter($scanResults, fn ($r) => ($r['status'] ?? null) === 'already_admitted'));

echo "  admission rows for the ticket: {$admissionCount}\n";
$assert($scanSuccesses === 1, 'exactly one gate reported a successful admission');
$assert($alreadyAdmitted === 1, 'the losing gate reported already_admitted');
$assert($admissionCount === 1, 'exactly one admission row exists (anti-passback holds)');
echo "\n";

// =============================================================================
// CASE C — The same client_scan_id is submitted for two different tickets
// =============================================================================
echo "CASE C: the same client_scan_id submitted for two different tickets\n";

$caseCStudents = collect([1, 2])->map(fn (int $n) => User::create([
    'name' => "Duplicate Key Attendee {$n}",
    'email' => "race.dup.{$n}.{$suffix}@mseuf.edu.ph",
    'student_id' => "RACE{$n}-{$suffix}",
    'role' => User::ROLE_STUDENT,
    'password' => Hash::make('password'),
]));

$caseCTickets = $caseCStudents->map(
    fn (User $student) => (new RegisterForEvent)->execute($student, $scanEvent->id)
);

$sharedScanId = (string) Str::uuid();

$duplicateKeyResults = $runConcurrently([
    ['scan', [
        'staff_user_id' => $scanStaff['A'],
        'event_id' => $scanEvent->id,
        'access_point_id' => $gateA->id,
        'qr_payload' => $caseCTickets[0]['qr_payload'],
        'client_scan_id' => $sharedScanId,
    ]],
    ['scan', [
        'staff_user_id' => $scanStaff['A'],
        'event_id' => $scanEvent->id,
        'access_point_id' => $gateA->id,
        'qr_payload' => $caseCTickets[1]['qr_payload'],
        'client_scan_id' => $sharedScanId,
    ]],
], $childScript, $testDatabase);

foreach ($duplicateKeyResults as $index => $result) {
    echo '  worker '.($index + 1).': '.json_encode($result).PHP_EOL;
}

$sharedKeyAdmissions = Admission::where('client_scan_id', $sharedScanId)->count();
$duplicateKeySuccesses = count(array_filter($duplicateKeyResults, fn ($r) => ($r['ok'] ?? false) === true));
$duplicateKeyConflicts = count(array_filter($duplicateKeyResults, fn ($r) => ($r['status'] ?? null) === 'idempotency_conflict'));
$duplicateKeyErrors = count(array_filter($duplicateKeyResults, fn ($r) => in_array($r['reason'] ?? null, ['exception', 'no_result'], true)));

$stalledInsertExplained = collect($duplicateKeyResults)->every(
    fn ($r) => ! ($r['attempted_admission_insert'] ?? false)
        || in_array($r['status'] ?? null, ['admitted', 'idempotency_conflict'], true)
);
$mappedPathObserved = collect($duplicateKeyResults)->contains(
    fn ($r) => ($r['attempted_admission_insert'] ?? false) === true && ($r['status'] ?? null) === 'idempotency_conflict'
);

echo "  admission rows for the shared scan id: {$sharedKeyAdmissions}\n";
echo '  unique-violation mapping exercised on this run: '.($mappedPathObserved ? 'yes' : 'no (pre-check path)').PHP_EOL;
$assert($duplicateKeySuccesses === 1, 'exactly one request was admitted');
$assert($duplicateKeyConflicts === 1, 'the replayed scan id was rejected with an idempotency conflict');
$assert($duplicateKeyErrors === 0, 'no worker surfaced an unhandled exception (no HTTP 500)');
$assert($stalledInsertExplained, 'every attempted admission insert resolved to a definite outcome');
$assert($sharedKeyAdmissions === 1, 'exactly one admission row carries the shared scan id');
echo "\n";

// =============================================================================
// CASE D — The unique-violation mapping is reached deterministically
// =============================================================================
// Hold a conflicting uncommitted row so the worker reaches the unique-index path deterministically.
echo "CASE D: unique-violation mapping under a held conflicting transaction\n";

$caseDStudents = collect([1, 2])->map(fn (int $n) => User::create([
    'name' => "Mapping Probe Attendee {$n}",
    'email' => "race.map.{$n}.{$suffix}@mseuf.edu.ph",
    'student_id' => "MAP{$n}-{$suffix}",
    'role' => User::ROLE_STUDENT,
    'password' => Hash::make('password'),
]));

$caseDTickets = $caseDStudents->map(
    fn (User $student) => (new RegisterForEvent)->execute($student, $scanEvent->id)
);

$heldScanId = (string) Str::uuid();

$heldConnection = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $baseConnection['host'],
        $baseConnection['port'],
        $testDatabase
    ),
    $baseConnection['username'],
    $baseConnection['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$heldConnection->beginTransaction();
$insertHeld = $heldConnection->prepare(
    'insert into admissions
        (ticket_id, access_point_id, scanned_by_user_id, client_scan_id, credential_version, admitted_at)
     values (:ticket_id, :access_point_id, :scanned_by, :scan_id, :credential_version, now())'
);
$insertHeld->execute([
    'ticket_id' => $caseDTickets[0]['ticket']->id,
    'access_point_id' => $gateA->id,
    'scanned_by' => $scanStaff['A'],
    'scan_id' => $heldScanId,
    'credential_version' => $caseDTickets[0]['ticket']->credential_version,
]);

$mappingProcess = proc_open(
    [PHP_BINARY, $childScript, 'scan', base64_encode(json_encode([
        'db' => $testDatabase,
        'staff_user_id' => $scanStaff['A'],
        'event_id' => $scanEvent->id,
        'access_point_id' => $gateA->id,
        'qr_payload' => $caseDTickets[1]['qr_payload'],
        'client_scan_id' => $heldScanId,
    ]))],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $mappingPipes,
    dirname($childScript, 2),
    null,
    ['bypass_shell' => true]
);

$t0 = microtime(true);
$workerReady = trim((string) fgets($mappingPipes[1])) === 'READY';
stream_set_write_buffer($mappingPipes[0], 0);
fwrite($mappingPipes[0], 'GO'.PHP_EOL);
fflush($mappingPipes[0]);

// Wait for PostgreSQL to report the worker blocked on the unique index.
$waitForBlockedInsert = $heldConnection->prepare(
    'select count(*) from pg_stat_activity
     where datname = :db
       and wait_event_type = :wait_type
       and pid <> pg_backend_pid()'
);

$blockedDeadline = microtime(true) + 20.0;
$blockedBackends = 0;

while (microtime(true) < $blockedDeadline) {
    $waitForBlockedInsert->execute(['db' => $testDatabase, 'wait_type' => 'Lock']);
    $blockedBackends = (int) $waitForBlockedInsert->fetchColumn();

    if ($blockedBackends > 0) {
        break;
    }

    usleep(50_000);
}

echo '  worker blocked on the unique index after '.round((microtime(true) - $t0) * 1000, 1)."ms (blocked backends: {$blockedBackends})\n";

$heldConnection->commit();

$mappingStdout = stream_get_contents($mappingPipes[1]);
$mappingStderr = stream_get_contents($mappingPipes[2]);
fclose($mappingPipes[0]);
fclose($mappingPipes[1]);
fclose($mappingPipes[2]);
proc_close($mappingProcess);

$mappingResult = ['ok' => false, 'reason' => 'no_result', 'detail' => trim($mappingStdout.' '.$mappingStderr)];
foreach (explode(PHP_EOL, (string) $mappingStdout) as $line) {
    if (str_starts_with($line, 'RESULT:')) {
        $mappingResult = json_decode(substr($line, 7), true) ?? $mappingResult;
    }
}

echo '  worker: '.json_encode($mappingResult).PHP_EOL;
echo '  admission rows for the held scan id: '.Admission::where('client_scan_id', $heldScanId)->count()."\n";

$assert($workerReady, 'the mapping-probe worker reached the barrier');
$assert(($mappingResult['attempted_admission_insert'] ?? false) === true, 'the worker reached the admission insert');
$assert(($mappingResult['status'] ?? null) === 'idempotency_conflict', 'the unique violation was mapped to an idempotency conflict');
$assert(($mappingResult['http_code'] ?? null) === 409, 'the mapped response used HTTP 409');
$assert(Admission::where('client_scan_id', $heldScanId)->count() === 1, 'no duplicate admission row was created');
echo "\n";

// =============================================================================
// CASE E — Event cancellation vs in-flight admission
// =============================================================================
// Hold an uncommitted event cancellation to verify that admission waits on the event lock.
echo "CASE E: a held event cancellation blocks an in-flight admission\n";

$raceStudents = collect([1, 2])->map(fn (int $n) => User::create([
    'name' => "Cancellation Race Attendee {$n}",
    'email' => "race.cancel.{$n}.{$suffix}@mseuf.edu.ph",
    'student_id' => "CXL{$n}-{$suffix}",
    'role' => User::ROLE_STUDENT,
    'password' => Hash::make('password'),
]));

$raceEvent = Event::create([
    'organizer_id' => $organizer->id,
    'venue_id' => $venue->id,
    'title' => 'Event Cancellation Race Event',
    'slug' => "event-cancellation-race-{$suffix}",
    'capacity' => 50,
    'registration_opens_at' => now()->subHour(),
    'registration_closes_at' => now()->addDay(),
    'starts_at' => now()->subMinutes(10),
    'ends_at' => now()->addHours(6),
    'status' => Event::STATUS_PUBLISHED,
]);

$raceGate = AccessPoint::create([
    'event_id' => $raceEvent->id,
    'name' => 'Race Gate',
    'code' => 'CXL-GATE',
    'is_active' => true,
]);

$raceStaff = User::create([
    'name' => 'Cancellation Race Staff',
    'email' => "race.cancel.staff.{$suffix}@mseuf.edu.ph",
    'role' => User::ROLE_STAFF,
    'password' => Hash::make('password'),
]);

EventStaff::create([
    'event_id' => $raceEvent->id,
    'user_id' => $raceStaff->id,
    'access_point_id' => $raceGate->id,
]);

$raceTickets = $raceStudents->map(
    fn (User $student) => (new RegisterForEvent)->execute($student, $raceEvent->id)
);

$rawConnection = function (string $database) use ($baseConnection): PDO {
    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $baseConnection['host'], $baseConnection['port'], $database),
        $baseConnection['username'],
        $baseConnection['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
};

$spawnWorker = function (string $mode, array $payload, string $jobDatabase, string $script): array {
    $payload['db'] = $jobDatabase;

    $process = proc_open(
        [PHP_BINARY, $script, $mode, base64_encode(json_encode($payload))],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname($script, 2),
        null,
        ['bypass_shell' => true]
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Failed to spawn worker.');
    }

    return [$process, $pipes];
};

$collectWorker = function ($process, array $pipes): array {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $result = null;
    foreach (explode(PHP_EOL, (string) $stdout) as $line) {
        if (str_starts_with($line, 'RESULT:')) {
            $result = json_decode(substr($line, 7), true);
        }
    }

    return $result ?? ['ok' => false, 'reason' => 'no_result', 'detail' => trim($stdout.' '.$stderr)];
};

$blockedBackends = function (PDO $connection, string $database): int {
    $statement = $connection->prepare(
        'select count(*) from pg_stat_activity
         where datname = :db and wait_event_type = :wait_type and pid <> pg_backend_pid()'
    );
    $statement->execute(['db' => $database, 'wait_type' => 'Lock']);

    return (int) $statement->fetchColumn();
};

$cancelConnection = $rawConnection($testDatabase);
$cancelConnection->beginTransaction();
$cancelConnection->prepare('update events set status = ?, updated_at = now() where id = ?')
    ->execute([Event::STATUS_CANCELLED, $raceEvent->id]);

[$cancelProcess, $cancelPipes] = $spawnWorker('scan', [
    'staff_user_id' => $raceStaff->id,
    'event_id' => $raceEvent->id,
    'access_point_id' => $raceGate->id,
    'qr_payload' => $raceTickets[0]['qr_payload'],
    'client_scan_id' => (string) Str::uuid(),
], $testDatabase, $childScript);

$cancelWorkerReady = trim((string) fgets($cancelPipes[1])) === 'READY';
stream_set_write_buffer($cancelPipes[0], 0);
fwrite($cancelPipes[0], 'GO'.PHP_EOL);
fflush($cancelPipes[0]);

$blockedDeadline = microtime(true) + 20.0;
$observedBlocked = 0;
while (microtime(true) < $blockedDeadline) {
    $observedBlocked = $blockedBackends($cancelConnection, $testDatabase);
    if ($observedBlocked > 0) {
        break;
    }
    usleep(50_000);
}

echo "  admission blocked on the cancelled event row (blocked backends: {$observedBlocked})\n";
$cancelConnection->commit();

$cancelResult = $collectWorker($cancelProcess, $cancelPipes);
echo '  worker: '.json_encode($cancelResult).PHP_EOL;

$raceAdmissions = Admission::where('ticket_id', $raceTickets[0]['ticket']->id)->count();
echo "  admission rows for the scanned ticket: {$raceAdmissions}\n";

$assert($cancelWorkerReady, 'the event-cancellation-race worker reached the barrier');
$assert($observedBlocked > 0, 'the admission blocked on the event row rather than racing past the event cancellation');
$assert(($cancelResult['status'] ?? null) === 'event_cancelled', 'the admission saw the committed event cancellation and was rejected');
$assert($raceAdmissions === 0, 'a cancelled event recorded no admission');
echo "\n";

// =============================================================================
// CASE F — Scanners for different tickets are not serialized by the event lock
// =============================================================================
// A held shared event lock should not serialize scans for different tickets.
echo "CASE F: two different tickets scan concurrently while a shared event lock is held\n";

$parallelEvent = Event::create([
    'organizer_id' => $organizer->id,
    'venue_id' => $venue->id,
    'title' => 'Parallel Scan Event',
    'slug' => "parallel-scan-{$suffix}",
    'capacity' => 50,
    'registration_opens_at' => now()->subHour(),
    'registration_closes_at' => now()->addDay(),
    'starts_at' => now()->subMinutes(10),
    'ends_at' => now()->addHours(6),
    'status' => Event::STATUS_PUBLISHED,
]);

$parallelGate = AccessPoint::create([
    'event_id' => $parallelEvent->id,
    'name' => 'Parallel Gate',
    'code' => 'PAR-GATE',
    'is_active' => true,
]);

EventStaff::create([
    'event_id' => $parallelEvent->id,
    'user_id' => $raceStaff->id,
    'access_point_id' => $parallelGate->id,
]);

$parallelStudents = collect([3, 4])->map(fn (int $n) => User::create([
    'name' => "Parallel Scan Attendee {$n}",
    'email' => "race.parallel.{$n}.{$suffix}@mseuf.edu.ph",
    'student_id' => "PAR{$n}-{$suffix}",
    'role' => User::ROLE_STUDENT,
    'password' => Hash::make('password'),
]));

$parallelTickets = $parallelStudents->map(
    fn (User $student) => (new RegisterForEvent)->execute($student, $parallelEvent->id)
);

$shareConnection = $rawConnection($testDatabase);
$shareConnection->beginTransaction();
$shareConnection->prepare('select id from events where id = ? for share')->execute([$parallelEvent->id]);

$shareStart = microtime(true);
$shareResults = $runConcurrently([
    ['scan', [
        'staff_user_id' => $raceStaff->id,
        'event_id' => $parallelEvent->id,
        'access_point_id' => $parallelGate->id,
        'qr_payload' => $parallelTickets[0]['qr_payload'],
        'client_scan_id' => (string) Str::uuid(),
    ]],
    ['scan', [
        'staff_user_id' => $raceStaff->id,
        'event_id' => $parallelEvent->id,
        'access_point_id' => $parallelGate->id,
        'qr_payload' => $parallelTickets[1]['qr_payload'],
        'client_scan_id' => (string) Str::uuid(),
    ]],
], $childScript, $testDatabase);

$shareConnection->commit();
$shareElapsed = microtime(true) - $shareStart;

foreach ($shareResults as $index => $result) {
    echo '  worker '.($index + 1).': '.json_encode($result).PHP_EOL;
}
echo '  both workers completed in '.round($shareElapsed, 2)."s while a shared event lock was held\n";

$shareAdmissions = Admission::whereIn('ticket_id', $parallelTickets->pluck('ticket.id')->all())->count();
$shareSuccesses = count(array_filter($shareResults, fn ($r) => ($r['ok'] ?? false) === true));

echo "  admission rows for both tickets: {$shareAdmissions}\n";
$assert($shareSuccesses === 2, 'both different-ticket scans were admitted despite the shared event lock');
$assert($shareAdmissions === 2, 'each ticket recorded exactly one admission');
echo "\n";

// =============================================================================
// CASE G — A held authorization reassignment blocks an in-flight admission
// =============================================================================
// Proves that admission confirmation authoritatively locks the staff authorization
// row under FOR UPDATE. If an organizer reassigns or revokes staff concurrently,
// the in-flight admission cannot race past with a stale authorization snapshot;
// it serializes on the authorization row, reads the committed change, and rejects.
echo "CASE G: a held authorization reassignment blocks an in-flight admission\n";

$authRaceEvent = Event::create([
    'organizer_id' => $organizer->id,
    'venue_id' => $venue->id,
    'title' => 'Authorization Race Event',
    'slug' => "auth-race-{$suffix}",
    'capacity' => 50,
    'registration_opens_at' => now()->subHour(),
    'registration_closes_at' => now()->addDay(),
    'starts_at' => now()->subMinutes(10),
    'ends_at' => now()->addHours(6),
    'status' => Event::STATUS_PUBLISHED,
]);

$authGate1 = AccessPoint::create([
    'event_id' => $authRaceEvent->id,
    'name' => 'Gate 1 North',
    'code' => 'G1-NORTH',
    'is_active' => true,
]);

$authGate2 = AccessPoint::create([
    'event_id' => $authRaceEvent->id,
    'name' => 'Gate 2 South',
    'code' => 'G2-SOUTH',
    'is_active' => true,
]);

$authRaceStaff = User::create([
    'name' => 'Auth Race Staff',
    'email' => "race.auth.staff.{$suffix}@mseuf.edu.ph",
    'role' => User::ROLE_STAFF,
    'password' => Hash::make('password'),
]);

$authAssignment = EventStaff::create([
    'event_id' => $authRaceEvent->id,
    'user_id' => $authRaceStaff->id,
    'access_point_id' => $authGate1->id,
]);

$authStudent = User::create([
    'name' => 'Auth Race Student',
    'email' => "race.auth.student.{$suffix}@mseuf.edu.ph",
    'student_id' => "AUTH-{$suffix}",
    'role' => User::ROLE_STUDENT,
    'password' => Hash::make('password'),
]);

$authTicket = (new RegisterForEvent)->execute($authStudent, $authRaceEvent->id);

$reassignConnection = $rawConnection($testDatabase);
$reassignConnection->beginTransaction();
$reassignConnection->prepare('update event_staff set access_point_id = ? where id = ?')
    ->execute([$authGate2->id, $authAssignment->id]);

[$authProcess, $authPipes] = $spawnWorker('scan', [
    'staff_user_id' => $authRaceStaff->id,
    'event_id' => $authRaceEvent->id,
    'access_point_id' => $authGate1->id,
    'qr_payload' => $authTicket['qr_payload'],
    'client_scan_id' => (string) Str::uuid(),
], $testDatabase, $childScript);

$authWorkerReady = trim((string) fgets($authPipes[1])) === 'READY';
stream_set_write_buffer($authPipes[0], 0);
fwrite($authPipes[0], 'GO'.PHP_EOL);
fflush($authPipes[0]);

$blockedDeadline = microtime(true) + 20.0;
$observedAuthBlocked = 0;
while (microtime(true) < $blockedDeadline) {
    $observedAuthBlocked = $blockedBackends($reassignConnection, $testDatabase);
    if ($observedAuthBlocked > 0) {
        break;
    }
    usleep(50_000);
}

echo "  admission blocked on the contested authorization row (blocked backends: {$observedAuthBlocked})\n";
$reassignConnection->commit();

$authResult = $collectWorker($authProcess, $authPipes);
echo '  worker: '.json_encode($authResult).PHP_EOL;

$authAdmissions = Admission::where('ticket_id', $authTicket['ticket']->id)->count();
echo "  admission rows for the scanned ticket: {$authAdmissions}\n";

$assert($authWorkerReady, 'the authorization-race worker reached the barrier');
$assert($observedAuthBlocked > 0, 'the admission blocked on the event_staff row rather than using a stale authorization snapshot');
$assert(($authResult['status'] ?? null) === 'gate_mismatch', 'the admission saw the committed reassignment and was rejected');
$assert($authAdmissions === 0, 'a reassigned gate recorded no admission');
echo "\n";

// =============================================================================
// CASE H - Competing runtimes cannot bind the same database
// =============================================================================
echo "CASE H: cloud and node compete to bind the same database\n";
DB::table('runtime_identity')->delete();
$runtimeResults = $runConcurrently([
    ['runtime', ['runtime' => 'cloud']],
    ['runtime', ['runtime' => 'node', 'node_id' => 'node-1']],
], $childScript, $testDatabase);
echo '  workers: '.json_encode($runtimeResults).PHP_EOL;
$runtimeCodes = array_column($runtimeResults, 'http_code');
sort($runtimeCodes);
$assert($runtimeCodes === [200, 503], 'only one runtime may bind the database');
$assert(DB::table('runtime_identity')->count() === 1, 'exactly one database identity exists');
echo "\n";

// =============================================================================
// CASE I - Registration waits for an in-flight cutoff
// =============================================================================
echo "CASE I: a held registration cutoff blocks an in-flight registration\n";
$cutoffEvent = Event::create([
    'organizer_id' => $organizer->id, 'venue_id' => $venue->id,
    'title' => 'Registration cutoff race', 'slug' => "cutoff-race-{$suffix}",
    'capacity' => 10, 'registration_opens_at' => now()->subHour(),
    'registration_closes_at' => now()->addDay(), 'starts_at' => now(),
    'ends_at' => now()->addDay(), 'status' => Event::STATUS_PUBLISHED,
]);
$cutoffStudent = User::create([
    'name' => 'Cutoff Race Student', 'email' => "cutoff.{$suffix}@mseuf.edu.ph",
    'student_id' => "CUTOFF-{$suffix}", 'role' => User::ROLE_STUDENT,
    'password' => Hash::make('password'),
]);
$cutoffConnection = $rawConnection($testDatabase);
$cutoffConnection->beginTransaction();
$cutoffConnection->prepare('update events set registration_closed_at = now() where id = ?')
    ->execute([$cutoffEvent->id]);
[$cutoffProcess, $cutoffPipes] = $spawnWorker('seat', [
    'user_id' => $cutoffStudent->id, 'event_id' => $cutoffEvent->id,
], $testDatabase, $childScript);
$cutoffWorkerReady = trim((string) fgets($cutoffPipes[1])) === 'READY';
fwrite($cutoffPipes[0], 'GO'.PHP_EOL);
fflush($cutoffPipes[0]);
$cutoffDeadline = microtime(true) + 20.0;
$cutoffBlocked = 0;
while (microtime(true) < $cutoffDeadline) {
    $cutoffBlocked = $blockedBackends($cutoffConnection, $testDatabase);
    if ($cutoffBlocked > 0) {
        break;
    }
    usleep(50_000);
}
$cutoffConnection->commit();
$cutoffResult = $collectWorker($cutoffProcess, $cutoffPipes);
echo '  worker: '.json_encode($cutoffResult).PHP_EOL;
$assert($cutoffWorkerReady, 'the registration worker reached the barrier');
$assert($cutoffBlocked > 0, 'registration waited for the cutoff event lock');
$assert(($cutoffResult['reason'] ?? null) === 'validation_error', 'registration rejected the committed cutoff');
$assert(Registration::where('event_id', $cutoffEvent->id)->count() === 0, 'no registration was issued after cutoff');
echo "\n";

// =============================================================================
// CASE Ticket Transfer - Snapshot finalization serializes against acceptance
// =============================================================================
echo "CASE Ticket Transfer: finalization expires an acceptance waiting on the event lock\n";
$transferRaceEvent = Event::create([
    'organizer_id' => $organizer->id, 'venue_id' => $venue->id,
    'title' => 'Transfer finalization race', 'slug' => "transfer-finalization-race-{$suffix}",
    'capacity' => 10, 'registration_opens_at' => now()->subDay(),
    'registration_closes_at' => now()->addDay(),
    'starts_at' => now(), 'ends_at' => now()->addDay(), 'status' => Event::STATUS_PUBLISHED,
    'ticket_transfers_allowed' => true,
]);
$transferSender = User::create([
    'name' => 'Transfer Race Sender', 'email' => "transfer.sender.{$suffix}@mseuf.edu.ph",
    'student_id' => "TRS-{$suffix}", 'role' => User::ROLE_STUDENT, 'email_verified_at' => now(),
    'password' => Hash::make('password'),
]);
$transferRecipient = User::create([
    'name' => 'Transfer Race Recipient', 'email' => "transfer.recipient.{$suffix}@mseuf.edu.ph",
    'student_id' => "TRR-{$suffix}", 'role' => User::ROLE_STUDENT, 'email_verified_at' => now(),
    'password' => Hash::make('password'),
]);
$transferTicket = (new RegisterForEvent)->execute($transferSender, $transferRaceEvent->id)['ticket'];
$transferRaceEvent->update(['registration_closed_at' => now()]);
$pendingTransfer = TicketTransfer::create([
    'event_id' => $transferRaceEvent->id, 'ticket_id' => $transferTicket->id,
    'ticket_public_id' => $transferTicket->public_id, 'pending_ticket_id' => $transferTicket->id,
    'from_user_id' => $transferSender->id, 'recipient_user_id' => $transferRecipient->id,
    'from_name' => $transferSender->name, 'from_email' => $transferSender->email,
    'recipient_name' => $transferRecipient->name, 'recipient_email' => $transferRecipient->email,
    'status' => TicketTransfer::STATUS_PENDING, 'requested_at' => now(),
]);
DB::beginTransaction();
DB::table('events')->where('id', $transferRaceEvent->id)->lockForUpdate()->first();
[$transferAcceptProcess, $transferAcceptPipes] = $spawnWorker('ticket_transfer_accept', [
    'transfer_id' => $pendingTransfer->id, 'recipient_id' => $transferRecipient->id,
], $testDatabase, $childScript);
$transferAcceptWorkerReady = trim((string) fgets($transferAcceptPipes[1])) === 'READY';
fwrite($transferAcceptPipes[0], 'GO'.PHP_EOL);
fflush($transferAcceptPipes[0]);
$transferAcceptDeadline = microtime(true) + 20.0;
$transferAcceptBlocked = 0;
while (microtime(true) < $transferAcceptDeadline) {
    $transferAcceptBlocked = $blockedBackends(DB::connection()->getPdo(), $testDatabase);
    if ($transferAcceptBlocked > 0) {
        break;
    }
    usleep(50_000);
}
$previousMode = config('ingress.mode');
config(['ingress.mode' => 'cloud']);
app(PrepareEventSnapshot::class)->execute($transferRaceEvent->id);
DB::commit();
config(['ingress.mode' => $previousMode]);
$transferAcceptResult = $collectWorker($transferAcceptProcess, $transferAcceptPipes);
echo '  worker: '.json_encode($transferAcceptResult).PHP_EOL;
$assert($transferAcceptWorkerReady, 'the transfer acceptance worker reached the barrier');
$assert($transferAcceptBlocked > 0, 'transfer acceptance waited for snapshot finalization on the event lock');
$assert(($transferAcceptResult['reason'] ?? null) === 'validation_error', 'acceptance rejected after finalization expired the request');
$assert(TicketTransfer::findOrFail($pendingTransfer->id)->status === TicketTransfer::STATUS_EXPIRED, 'snapshot finalization expired the pending transfer');
$assert($transferTicket->fresh()->registration->user_id === $transferSender->id, 'the original ticket owner retained the ticket');
echo "\n";

// =============================================================================
// CASE Ticket Transfer Acceptance - Acceptance commits before snapshot capture
// =============================================================================
echo "CASE Ticket Transfer Acceptance: a waiting snapshot captures accepted ownership and credential\n";
$acceptedBeforeSnapshotEvent = Event::create([
    'organizer_id' => $organizer->id, 'venue_id' => $venue->id,
    'title' => 'Transfer acceptance race', 'slug' => "transfer-acceptance-race-{$suffix}",
    'capacity' => 10, 'registration_opens_at' => now()->subDay(),
    'registration_closes_at' => now()->addDay(),
    'starts_at' => now(), 'ends_at' => now()->addDay(), 'status' => Event::STATUS_PUBLISHED,
    'ticket_transfers_allowed' => true,
]);
$acceptRaceSender = User::create([
    'name' => 'Acceptance Race Sender', 'email' => "accept.sender.{$suffix}@mseuf.edu.ph",
    'student_id' => "ARS-{$suffix}", 'role' => User::ROLE_STUDENT,
    'password' => Hash::make('password'),
]);
$acceptRaceRecipient = User::create([
    'name' => 'Acceptance Race Recipient', 'email' => "accept.recipient.{$suffix}@mseuf.edu.ph",
    'student_id' => "ARR-{$suffix}", 'role' => User::ROLE_STUDENT,
    'password' => Hash::make('password'),
]);
$acceptRaceSender->forceFill(['email_verified_at' => now()])->save();
$acceptRaceRecipient->forceFill(['email_verified_at' => now()])->save();
$acceptRaceTicket = (new RegisterForEvent)->execute($acceptRaceSender, $acceptedBeforeSnapshotEvent->id)['ticket'];
$acceptedBeforeSnapshotEvent->update(['registration_closed_at' => now()]);
$acceptRaceTransfer = TicketTransfer::create([
    'event_id' => $acceptedBeforeSnapshotEvent->id, 'ticket_id' => $acceptRaceTicket->id,
    'ticket_public_id' => $acceptRaceTicket->public_id, 'pending_ticket_id' => $acceptRaceTicket->id,
    'from_user_id' => $acceptRaceSender->id, 'recipient_user_id' => $acceptRaceRecipient->id,
    'from_name' => $acceptRaceSender->name, 'from_email' => $acceptRaceSender->email,
    'recipient_name' => $acceptRaceRecipient->name, 'recipient_email' => $acceptRaceRecipient->email,
    'status' => TicketTransfer::STATUS_PENDING, 'requested_at' => now(),
]);
DB::beginTransaction();
DB::table('events')->where('id', $acceptedBeforeSnapshotEvent->id)->lockForUpdate()->first();
$acceptRequest = Request::create('/api/v1/ticket-transfers/'.$acceptRaceTransfer->id.'/accept', 'POST');
$acceptRequest->setUserResolver(fn () => $acceptRaceRecipient);
$previousMode = config('ingress.mode');
config(['ingress.mode' => 'cloud']);
$acceptResponse = app(TicketTransferController::class)->accept($acceptRequest, TicketTransfer::findOrFail($acceptRaceTransfer->id));
[$acceptanceSnapshotProcess, $acceptanceSnapshotPipes] = $spawnWorker('snapshot', [
    'event_id' => $acceptedBeforeSnapshotEvent->id,
], $testDatabase, $childScript);
$acceptanceSnapshotWorkerReady = trim((string) fgets($acceptanceSnapshotPipes[1])) === 'READY';
fwrite($acceptanceSnapshotPipes[0], 'GO'.PHP_EOL);
fflush($acceptanceSnapshotPipes[0]);
$acceptanceSnapshotDeadline = microtime(true) + 20.0;
$acceptanceSnapshotBlocked = 0;
while (microtime(true) < $acceptanceSnapshotDeadline) {
    $acceptanceSnapshotBlocked = $blockedBackends(DB::connection()->getPdo(), $testDatabase);
    if ($acceptanceSnapshotBlocked > 0) {
        break;
    }
    usleep(50_000);
}
DB::commit();
config(['ingress.mode' => $previousMode]);
$acceptanceSnapshotResult = $collectWorker($acceptanceSnapshotProcess, $acceptanceSnapshotPipes);
echo '  worker: '.json_encode($acceptanceSnapshotResult).PHP_EOL;
$acceptanceSnapshotRecord = DB::table('event_snapshots')->where('event_id', $acceptedBeforeSnapshotEvent->id)->first();
$acceptedSnapshotPayload = $acceptanceSnapshotRecord
    ? json_decode(Crypt::decryptString($acceptanceSnapshotRecord->payload_ciphertext), true)
    : null;
$snapshotRegistration = $acceptedSnapshotPayload['registrations'][0] ?? null;
$assert($acceptResponse->getStatusCode() === 200, 'recipient acceptance committed before snapshot finalization');
$assert($acceptanceSnapshotWorkerReady, 'the snapshot worker reached the barrier');
$assert($acceptanceSnapshotBlocked > 0, 'snapshot preparation waited for transfer acceptance on the event lock');
$assert(($acceptanceSnapshotResult['ok'] ?? false) && $acceptedSnapshotPayload !== null, 'snapshot preparation completed after acceptance');
$assert($acceptRaceTicket->fresh()->registration->user_id === $acceptRaceRecipient->id, 'the recipient owns the ticket after acceptance');
$assert((int) ($snapshotRegistration['attendee']['id'] ?? 0) === $acceptRaceRecipient->id, 'the snapshot contains the accepted owner');
$assert(($snapshotRegistration['ticket']['qr_hash'] ?? null) === $acceptRaceTicket->fresh()->qr_hash, 'the snapshot contains the rotated credential hash');
echo "\n";

// =============================================================================
// CASE Guest Ticket Redemption — one use wins under PostgreSQL row locking
// =============================================================================
echo "CASE guest ticket redemption: concurrent reuse of one code rotates once\n";
config(['ingress.mode' => 'cloud']);
$guestRedemptionEvent = Event::create([
    'organizer_id' => $organizer->id, 'venue_id' => $venue->id,
    'title' => 'Guest redemption race', 'slug' => "guest-redemption-race-{$suffix}",
    'capacity' => 10, 'registration_opens_at' => now()->subDay(),
    'registration_closes_at' => now()->addDay(),
    'starts_at' => now(), 'ends_at' => now()->addDay(), 'status' => Event::STATUS_PUBLISHED,
    'ticket_transfers_allowed' => true,
]);
$guestRedemptionCategory = $guestRedemptionEvent->ticketCategories()
    ->where('is_default', true)
    ->firstOrFail();
$guestRedemptionResult = app(RegisterAttendeeForEvent::class)->execute(
    eventId: $guestRedemptionEvent->id,
    categoryId: $guestRedemptionCategory->id,
    guestName: 'Race Guest',
    guestEmail: "guest.redemption.{$suffix}@example.test",
);
$guestRedemptionTicket = $guestRedemptionResult['ticket'];
$guestRedemptionCode = $guestRedemptionResult['redemption_code'];
$guestOldQrHash = $guestRedemptionTicket->qr_hash;
$guestOldQr = $guestRedemptionTicket->getQrPayload();
$guestOldSecret = explode(':', $guestOldQr)[3];

DB::beginTransaction();
DB::table('events')->where('id', $guestRedemptionEvent->id)->lockForUpdate()->first();
[$guestRedemptionProcessA, $guestRedemptionPipesA] = $spawnWorker('guest_ticket_redeem', [
], $testDatabase, $childScript);
[$guestRedemptionProcessB, $guestRedemptionPipesB] = $spawnWorker('guest_ticket_redeem', [
], $testDatabase, $childScript);
$guestRedemptionReadyA = trim((string) fgets($guestRedemptionPipesA[1])) === 'READY';
$guestRedemptionReadyB = trim((string) fgets($guestRedemptionPipesB[1])) === 'READY';
foreach ([$guestRedemptionPipesA, $guestRedemptionPipesB] as $guestRedemptionPipes) {
    fwrite($guestRedemptionPipes[0], 'GO'.PHP_EOL.$guestRedemptionCode.PHP_EOL);
    fflush($guestRedemptionPipes[0]);
}
$guestRedemptionDeadline = microtime(true) + 20.0;
$guestRedemptionBlocked = 0;
while (microtime(true) < $guestRedemptionDeadline) {
    $guestRedemptionBlocked = $blockedBackends(DB::connection()->getPdo(), $testDatabase);
    if ($guestRedemptionBlocked >= 2) {
        break;
    }
    usleep(50_000);
}
DB::commit();
$guestRedemptionResults = [
    $collectWorker($guestRedemptionProcessA, $guestRedemptionPipesA),
    $collectWorker($guestRedemptionProcessB, $guestRedemptionPipesB),
];
echo '  workers: '.json_encode($guestRedemptionResults).PHP_EOL;
$guestRedemptionOutput = json_encode($guestRedemptionResults, JSON_THROW_ON_ERROR);
$guestRedemptionTicketAfterRace = $guestRedemptionTicket->fresh();
$guestRotationCount = DB::table('guest_ticket_credential_rotations')
    ->where('ticket_id', $guestRedemptionTicket->id)->where('kind', 'redeemed')->count();
$successfulGuestRedemptions = count(array_filter($guestRedemptionResults, fn (array $result): bool => ($result['ok'] ?? false) === true));
$rejectedGuestRedemptions = count(array_filter($guestRedemptionResults, fn (array $result): bool => ($result['reason'] ?? null) === 'validation_error'));
$assert($guestRedemptionReadyA && $guestRedemptionReadyB, 'both redemption workers reached the PostgreSQL barrier');
$assert($guestRedemptionBlocked >= 2, 'both redemption attempts waited on the same event lock');
$assert(! str_contains($guestRedemptionOutput, $guestRedemptionCode), 'worker output never includes the redemption code');
$assert($successfulGuestRedemptions === 1 && $rejectedGuestRedemptions === 1, 'one redemption succeeds and one replay is rejected');
$assert((int) $guestRedemptionTicketAfterRace->credential_version === 2, 'the ticket credential version increments only once');
$assert($guestRedemptionTicketAfterRace->qr_hash !== $guestOldQrHash
    && ! $guestRedemptionTicketAfterRace->verifySecret($guestOldSecret), 'the old QR is invalid after the winning redemption');
$assert($guestRotationCount === 1
    && DB::table('guest_ticket_credential_rotations')->where('ticket_id', $guestRedemptionTicket->id)->count() === 2,
    'one redemption audit row follows the initial issuance row');
echo "\n";

// =============================================================================
// CASE J - Concurrent preparation produces one version
// =============================================================================
echo "CASE J: concurrent snapshot preparation returns one version\n";
$snapshotEvent = Event::create([
    'organizer_id' => $organizer->id, 'venue_id' => $venue->id,
    'title' => 'Snapshot race', 'slug' => "snapshot-race-{$suffix}",
    'capacity' => 10, 'registration_opens_at' => now()->subDay(),
    'registration_closes_at' => now()->addDay(), 'registration_closed_at' => now(),
    'starts_at' => now(), 'ends_at' => now()->addDay(), 'status' => Event::STATUS_PUBLISHED,
]);
$snapshotResults = $runConcurrently([
    ['snapshot', ['event_id' => $snapshotEvent->id]],
    ['snapshot', ['event_id' => $snapshotEvent->id]],
], $childScript, $testDatabase);
echo '  workers: '.json_encode($snapshotResults).PHP_EOL;
$assert(($snapshotResults[0]['ok'] ?? false) && ($snapshotResults[1]['ok'] ?? false), 'both preparation requests completed');
$assert(! empty($snapshotResults[0]['id']) && ($snapshotResults[0]['id'] ?? null) === ($snapshotResults[1]['id'] ?? null), 'both requests returned the same snapshot');
$assert(DB::table('event_snapshots')->where('event_id', $snapshotEvent->id)->count() === 1, 'only one snapshot version was stored');
echo "\n";

// =============================================================================
// CASE K - Preparation waits for an event and gate update
// =============================================================================
echo "CASE K: snapshot waits for a concurrent event and gate update\n";
$gateSnapshotEvent = Event::create([
    'organizer_id' => $organizer->id, 'venue_id' => $venue->id,
    'title' => 'Before update', 'slug' => "snapshot-gate-race-{$suffix}",
    'capacity' => 10, 'registration_opens_at' => now()->subDay(),
    'registration_closes_at' => now()->addDay(), 'registration_closed_at' => now(),
    'starts_at' => now(), 'ends_at' => now()->addDay(), 'status' => Event::STATUS_PUBLISHED,
]);
$gateConnection = $rawConnection($testDatabase);
$gateConnection->beginTransaction();
$gateConnection->prepare('update events set title = ? where id = ?')->execute(['After update', $gateSnapshotEvent->id]);
$gateConnection->prepare('insert into access_points (event_id, name, code, is_active, created_at, updated_at) values (?, ?, ?, true, now(), now())')
    ->execute([$gateSnapshotEvent->id, 'Committed Gate', 'COMMITTED']);
[$snapshotProcess, $snapshotPipes] = $spawnWorker('snapshot', ['event_id' => $gateSnapshotEvent->id], $testDatabase, $childScript);
$snapshotReady = trim((string) fgets($snapshotPipes[1])) === 'READY';
fwrite($snapshotPipes[0], 'GO'.PHP_EOL);
fflush($snapshotPipes[0]);
$snapshotDeadline = microtime(true) + 20.0;
$snapshotBlocked = 0;
while (microtime(true) < $snapshotDeadline) {
    $snapshotBlocked = $blockedBackends($gateConnection, $testDatabase);
    if ($snapshotBlocked > 0) {
        break;
    }
    usleep(50_000);
}
$gateConnection->commit();
$snapshotResult = $collectWorker($snapshotProcess, $snapshotPipes);
$snapshotRow = DB::table('event_snapshots')->where('event_id', $gateSnapshotEvent->id)->first();
$snapshotPayload = $snapshotRow ? json_decode(Crypt::decryptString($snapshotRow->payload_ciphertext), true) : [];
$assert($snapshotReady, 'the snapshot worker reached the barrier');
$assert($snapshotBlocked > 0, 'snapshot preparation waited for the event lock');
$assert(($snapshotResult['ok'] ?? false) === true, 'snapshot preparation completed after the update');
$assert(($snapshotPayload['event']['title'] ?? null) === 'After update', 'snapshot includes the committed event update');
$assert(($snapshotPayload['access_points'][0]['code'] ?? null) === 'COMMITTED', 'snapshot includes the committed gate');
echo "\n";

// =============================================================================
// CASE L - Idempotent concurrent receipt on a single node
// =============================================================================
echo "CASE L: concurrent receipt of one snapshot is idempotent\n";
DB::table('runtime_identity')->delete();
$nodeSecret = base64_encode(random_bytes(32));
$sourceSnapshot = DB::table('event_snapshots')->where('event_id', $snapshotEvent->id)->first();
$sourceJson = Crypt::decryptString($sourceSnapshot->payload_ciphertext);
$sealed = app(SnapshotEnvelope::class)->seal('race-node', $nodeSecret, $sourceJson);
$receiveJobs = [
    ['receive', ['node_id' => 'race-node', 'secret' => $nodeSecret, 'sealed' => $sealed]],
    ['receive', ['node_id' => 'race-node', 'secret' => $nodeSecret, 'sealed' => $sealed]],
];
$receiveResults = $runConcurrently($receiveJobs, $childScript, $testDatabase);
$assert(($receiveResults[0]['ok'] ?? false) && ($receiveResults[1]['ok'] ?? false), 'both receipt retries succeeded');
$assert(! empty($receiveResults[0]['receipt_hash']) && $receiveResults[0]['receipt_hash'] === ($receiveResults[1]['receipt_hash'] ?? null), 'both retries produced the same receipt');
$assert(DB::table('received_snapshots')->where('event_id', $snapshotEvent->id)->count() === 1, 'only one staged snapshot exists');
echo "\n";

// =============================================================================
// CASE M - Conflicting packages for one version cannot both be staged
// =============================================================================
echo "CASE M: conflicting packages for one event version race\n";
$otherSource = DB::table('event_snapshots')->where('event_id', $gateSnapshotEvent->id)->first();
$firstJson = Crypt::decryptString($otherSource->payload_ciphertext);
$otherPayload = json_decode($firstJson, true, 512, JSON_THROW_ON_ERROR);
$otherPayload['snapshot_id'] = (string) Str::uuid();
$otherJson = json_encode($otherPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$conflictJobs = [
    ['receive', ['node_id' => 'race-node', 'secret' => $nodeSecret, 'sealed' => app(SnapshotEnvelope::class)->seal('race-node', $nodeSecret, $firstJson)]],
    ['receive', ['node_id' => 'race-node', 'secret' => $nodeSecret, 'sealed' => app(SnapshotEnvelope::class)->seal('race-node', $nodeSecret, $otherJson)]],
];
$conflictResults = $runConcurrently($conflictJobs, $childScript, $testDatabase);
$successes = count(array_filter($conflictResults, fn ($result) => ($result['ok'] ?? false) === true));
$rejected = count(array_filter($conflictResults, fn ($result) => ($result['reason'] ?? null) === 'validation_error'));
$assert($successes === 1 && $rejected === 1, 'exactly one conflicting package was accepted');
$assert(DB::table('received_snapshots')->where('event_id', $gateSnapshotEvent->id)->count() === 1, 'only one package for that event version was staged');
echo "\n";

// =============================================================================
// CASE N - Concurrent node registration does not issue two secrets
// =============================================================================
echo "CASE N: concurrent registration of one node\n";
DB::table('runtime_identity')->delete();
DB::table('runtime_identity')->insert(['id' => 1, 'mode' => 'cloud', 'identity' => 'cloud']);
$nodeJobs = [
    ['register_node', ['node_id' => 'registered-race-node']],
    ['register_node', ['node_id' => 'registered-race-node']],
];
$nodeResults = $runConcurrently($nodeJobs, $childScript, $testDatabase);
$issued = count(array_filter($nodeResults, fn ($result) => ($result['ok'] ?? false) && ($result['key_shown'] ?? false)));
$rejected = count(array_filter($nodeResults, fn ($result) => ! ($result['ok'] ?? false) && ! ($result['key_shown'] ?? false)));
$assert($issued === 1 && $rejected === 1, 'exactly one registration issued a secret');
$assert(DB::table('event_nodes')->where('id', 'registered-race-node')->count() === 1, 'exactly one node credential exists');
echo "\n";

// =============================================================================
// CASE O - A snapshot cannot be exported to competing nodes
// =============================================================================
echo "CASE O: concurrent export to two nodes\n";
foreach (['target-a', 'target-b'] as $target) {
    DB::table('event_nodes')->insert([
        'id' => $target, 'key_ciphertext' => Crypt::encryptString(base64_encode(random_bytes(32))),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
$exportJobs = [
    ['export', ['node_id' => 'target-a', 'event_id' => $snapshotEvent->id]],
    ['export', ['node_id' => 'target-b', 'event_id' => $snapshotEvent->id]],
];
$exportResults = $runConcurrently($exportJobs, $childScript, $testDatabase);
$exports = count(array_filter($exportResults, fn ($result) => ($result['ok'] ?? false) === true));
$rejections = count(array_filter($exportResults, fn ($result) => ($result['reason'] ?? null) === 'validation_error'));
$target = DB::table('event_snapshots')->where('event_id', $snapshotEvent->id)->value('target_node_id');
$assert($exports === 1 && $rejections === 1, 'only one target received an export');
$assert(in_array($target, ['target-a', 'target-b'], true), 'snapshot retained the winning target');
echo "\n";

// =============================================================================
// CASE O2 - Transfer waits for a concurrent cloud cancellation
// =============================================================================
echo "CASE O2: authority transfer waits for cloud cancellation\n";
$transferSnapshot = DB::table('event_snapshots')->where('event_id', $snapshotEvent->id)->first();
foreach (['event_snapshot_receipts', 'event_snapshot_import_receipts'] as $receiptTable) {
    DB::table($receiptTable)->insert([
        'snapshot_id' => $transferSnapshot->id, 'node_id' => $target, 'sha256' => $transferSnapshot->sha256, 'received_at' => now(),
    ]);
}
$cloudKeys = sodium_crypto_sign_keypair();
$cancelConnection = $rawConnection($testDatabase);
$cancelConnection->beginTransaction();
$cancelConnection->prepare("update events set status = 'cancelled' where id = ?")->execute([$snapshotEvent->id]);
[$transferProcess, $transferPipes] = $spawnWorker('transfer_authority', [
    'event_id' => $snapshotEvent->id, 'private_key' => base64_encode(sodium_crypto_sign_secretkey($cloudKeys)),
    'public_key' => base64_encode(sodium_crypto_sign_publickey($cloudKeys)),
], $testDatabase, $childScript);
$transferReady = trim((string) fgets($transferPipes[1])) === 'READY';
fwrite($transferPipes[0], 'GO'.PHP_EOL);
fflush($transferPipes[0]);
$transferDeadline = microtime(true) + 20.0;
$transferBlocked = 0;
while (microtime(true) < $transferDeadline) {
    $transferBlocked = $blockedBackends($cancelConnection, $testDatabase);
    if ($transferBlocked > 0) {
        break;
    }
    usleep(50_000);
}
$cancelConnection->commit();
$transferResult = $collectWorker($transferProcess, $transferPipes);
$assert($transferReady, 'authority transfer worker reached the barrier');
$assert($transferBlocked > 0, 'transfer waited for the event lock');
$assert(($transferResult['reason'] ?? null) === 'validation_error', 'transfer rejected the cancelled event');
$assert(DB::table('cloud_event_authorities')->where('event_id', $snapshotEvent->id)->count() === 0, 'no authority grant was stored');
echo "\n";

// =============================================================================
// CASE P - Reused snapshot identifiers across events fail closed
// =============================================================================
echo "CASE P: one snapshot ID is reused across two events\n";
DB::table('runtime_identity')->delete();
$firstPayload = json_decode($firstJson, true, 512, JSON_THROW_ON_ERROR);
$firstPayload['snapshot_id'] = (string) Str::uuid();
$firstPayload['event']['id'] = $gateSnapshotEvent->id + 100000;
$secondPayload = $firstPayload;
$secondPayload['event']['id']++;
$firstPackage = app(SnapshotEnvelope::class)->seal('race-node', $nodeSecret, json_encode($firstPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$secondPackage = app(SnapshotEnvelope::class)->seal('race-node', $nodeSecret, json_encode($secondPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
$duplicateIdJobs = [
    ['receive', ['node_id' => 'race-node', 'secret' => $nodeSecret, 'sealed' => $firstPackage]],
    ['receive', ['node_id' => 'race-node', 'secret' => $nodeSecret, 'sealed' => $secondPackage]],
];
$duplicateIdResults = $runConcurrently($duplicateIdJobs, $childScript, $testDatabase);
echo '  workers: '.json_encode($duplicateIdResults).PHP_EOL;
$successes = count(array_filter($duplicateIdResults, fn ($result) => ($result['ok'] ?? false) === true));
$rejections = count(array_filter($duplicateIdResults, fn ($result) => ($result['reason'] ?? null) === 'validation_error'));
$assert($successes === 1 && $rejections === 1, 'a reused snapshot ID is rejected without an unhandled error');
$assert(DB::table('received_snapshots')->where('id', $firstPayload['snapshot_id'])->count() === 1, 'only one snapshot with that ID was staged');
echo "\n";

// =============================================================================
// CASE Q - Concurrent imports create one local event
// =============================================================================
echo "CASE Q: concurrent import of one snapshot is idempotent\n";
$importPayload = json_decode($sourceJson, true, 512, JSON_THROW_ON_ERROR);
$importPayload['snapshot_id'] = (string) Str::uuid();
$importPayload['event']['id'] += 200000;
$importPayload['event']['slug'] = "import-race-{$suffix}";
$importPayload['event']['organizer']['id'] += 200000;
$importPayload['event']['organizer']['email'] = "import-{$suffix}@example.test";
$importPayload['event']['venue']['id'] += 200000;
$importPayload['event']['venue']['code'] = "IMP{$suffix}";
$importJson = json_encode($importPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
config(['ingress.mode' => 'node', 'ingress.node_id' => 'race-node', 'ingress.node_sync_key' => $nodeSecret]);
app(ReceiveSnapshot::class)->execute(app(SnapshotEnvelope::class)->seal('race-node', $nodeSecret, $importJson));
$importJobs = [
    ['import', ['node_id' => 'race-node', 'event_id' => $importPayload['event']['id']]],
    ['import', ['node_id' => 'race-node', 'event_id' => $importPayload['event']['id']]],
];
$importResults = $runConcurrently($importJobs, $childScript, $testDatabase);
echo '  workers: '.json_encode($importResults).PHP_EOL;
$assert(($importResults[0]['ok'] ?? false) && ($importResults[1]['ok'] ?? false), 'both imports completed');
$assert(! empty($importResults[0]['event_id']) && ($importResults[0]['event_id'] ?? null) === ($importResults[1]['event_id'] ?? null), 'both imports returned the same node event');
$assert(DB::table('node_event_imports')->where('cloud_event_id', $importPayload['event']['id'])->count() === 1, 'only one event import exists');
echo "\n";

// =============================================================================
// CASE R - A verification link can create at most one account
// =============================================================================
echo "CASE R: concurrent use of one student verification link\n";
$verificationToken = bin2hex(random_bytes(32));
$verificationEmail = "verify-{$suffix}@student.mseuf.edu.ph";
DB::table('student_email_verifications')->insert([
    'email' => $verificationEmail, 'token_hash' => hash('sha256', $verificationToken),
    'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
]);
$verificationJobs = [
    ['student_complete', ['token' => $verificationToken, 'student_id' => "R{$suffix}A"]],
    ['student_complete', ['token' => $verificationToken, 'student_id' => "R{$suffix}B"]],
];
$verificationResults = $runConcurrently($verificationJobs, $childScript, $testDatabase);
echo '  workers: '.json_encode($verificationResults).PHP_EOL;
$assert(count(array_filter($verificationResults, fn ($result) => ($result['http_code'] ?? null) === 200)) === 1, 'one completion succeeds');
$assert(count(array_filter($verificationResults, fn ($result) => ($result['http_code'] ?? null) === 422)) === 1, 'the replay is rejected');
$assert(User::where('email', $verificationEmail)->count() === 1, 'exactly one account exists');
echo "\n";

// =============================================================================
// CASE S - Distinct links cannot claim the same student number
// =============================================================================
echo "CASE S: concurrent claims for one student number\n";
$studentNumber = strtoupper("S{$suffix}");
$claimJobs = [];
foreach (['one', 'two'] as $claimant) {
    $token = bin2hex(random_bytes(32));
    DB::table('student_email_verifications')->insert([
        'email' => "{$claimant}-{$suffix}@student.mseuf.edu.ph", 'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $claimJobs[] = ['student_complete', ['token' => $token, 'student_id' => $studentNumber]];
}
$claimResults = $runConcurrently($claimJobs, $childScript, $testDatabase);
echo '  workers: '.json_encode($claimResults).PHP_EOL;
$assert(count(array_filter($claimResults, fn ($result) => ($result['http_code'] ?? null) === 200)) === 1, 'one claim succeeds');
$assert(count(array_filter($claimResults, fn ($result) => ($result['http_code'] ?? null) === 422)) === 1, 'the conflicting claim is rejected');
$assert(User::where('student_id', $studentNumber)->count() === 1, 'exactly one student number exists');
echo "\n";

// =============================================================================
// CASE T - Concurrent refreshes of an imported snapshot are idempotent
// =============================================================================
echo "CASE T: concurrent refresh of one imported event\n";
$refreshPayload = $importPayload;
$refreshPayload['snapshot_id'] = (string) Str::uuid();
$refreshPayload['version'] = 2;
$refreshPayload['event']['title'] = 'Refreshed event';
$refreshJson = json_encode($refreshPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
app(ReceiveSnapshot::class)->execute(app(SnapshotEnvelope::class)->seal('race-node', $nodeSecret, $refreshJson));
$refreshJobs = [
    ['import', ['node_id' => 'race-node', 'event_id' => $refreshPayload['event']['id']]],
    ['import', ['node_id' => 'race-node', 'event_id' => $refreshPayload['event']['id']]],
];
$refreshResults = $runConcurrently($refreshJobs, $childScript, $testDatabase);
echo '  workers: '.json_encode($refreshResults).PHP_EOL;
$assert(($refreshResults[0]['ok'] ?? false) && ($refreshResults[1]['ok'] ?? false), 'both refreshes completed');
$assert(($refreshResults[0]['event_id'] ?? null) === ($importResults[0]['event_id'] ?? null)
    && ($refreshResults[1]['event_id'] ?? null) === ($importResults[0]['event_id'] ?? null), 'refresh retains the node event ID');
$assert(DB::table('node_event_imports')->where('cloud_event_id', $refreshPayload['event']['id'])->where('version', 2)->count() === 1, 'one refreshed version exists');
echo "\n";

// =============================================================================
// CASE U - Refresh waits for an admission and then preserves it
// =============================================================================
echo "CASE U: refresh waits for an in-flight admission\n";
$racePayload = json_decode($firstJson, true, 512, JSON_THROW_ON_ERROR);
$racePayload['snapshot_id'] = (string) Str::uuid();
$racePayload['event']['id'] += 400000;
$racePayload['event']['slug'] = "refresh-admission-{$suffix}";
$racePayload['event']['organizer']['id'] += 400000;
$racePayload['event']['organizer']['email'] = "refresh-owner-{$suffix}@example.test";
$racePayload['event']['venue']['id'] += 400000;
$racePayload['event']['venue']['code'] = "REF{$suffix}";
$raceGateId = $gateSnapshotEvent->id + 400000;
$racePayload['access_points'][0]['id'] = $raceGateId;
$racePayload['staff'] = [['id' => $raceGateId, 'access_point_id' => $raceGateId,
    'user' => ['id' => $raceGateId + 1, 'name' => 'Refresh Staff', 'email' => "refresh-staff-{$suffix}@example.test", 'role' => 'staff']]];
$racePayload['registrations'] = [['id' => $raceGateId, 'status' => 'confirmed', 'created_at' => now()->toISOString(), 'cancelled_at' => null,
    'attendee' => ['id' => $raceGateId + 2, 'name' => 'Refresh Attendee', 'email' => "refresh-student-{$suffix}@example.test", 'student_id' => null],
    'ticket' => ['id' => $raceGateId, 'public_id' => "refresh-{$suffix}", 'qr_hash' => hash('sha256', 'refresh-test-secret'),
        'status' => 'active', 'credential_version' => 1, 'issued_at' => now()->toISOString(), 'revoked_at' => null]]];
$raceJson = json_encode($racePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
app(ReceiveSnapshot::class)->execute(app(SnapshotEnvelope::class)->seal('race-node', $nodeSecret, $raceJson));
$raceEventId = app(ImportReceivedSnapshot::class)->execute($racePayload['event']['id']);
$racePayload['snapshot_id'] = (string) Str::uuid();
$racePayload['version'] = 2;
$racePayload['event']['title'] = 'New snapshot';
$raceJson = json_encode($racePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
app(ReceiveSnapshot::class)->execute(app(SnapshotEnvelope::class)->seal('race-node', $nodeSecret, $raceJson));
$localTicket = DB::table('node_import_ids')->where('kind', 'ticket')->where('cloud_id', $raceGateId)->value('local_id');
$localGate = DB::table('node_import_ids')->where('kind', 'gate')->where('cloud_id', $raceGateId)->value('local_id');
$localStaff = DB::table('node_import_ids')->where('kind', 'user')->where('cloud_id', $raceGateId + 1)->value('local_id');
$admissionConnection = $rawConnection($testDatabase);
$admissionConnection->beginTransaction();
$admissionConnection->prepare('select id from events where id = ? for share')->execute([$raceEventId]);
$admissionConnection->prepare('insert into admissions (ticket_id, access_point_id, scanned_by_user_id, client_scan_id, credential_version, admitted_at, created_at, updated_at) values (?, ?, ?, ?, 1, now(), now(), now())')
    ->execute([$localTicket, $localGate, $localStaff, "refresh-race-{$suffix}"]);
[$refreshProcess, $refreshPipes] = $spawnWorker('import', ['node_id' => 'race-node', 'event_id' => $racePayload['event']['id']], $testDatabase, $childScript);
$refreshReady = trim((string) fgets($refreshPipes[1])) === 'READY';
fwrite($refreshPipes[0], 'GO'.PHP_EOL);
fflush($refreshPipes[0]);
$refreshDeadline = microtime(true) + 20.0;
$refreshBlocked = 0;
while (microtime(true) < $refreshDeadline) {
    $refreshBlocked = $blockedBackends($admissionConnection, $testDatabase);
    if ($refreshBlocked > 0) {
        break;
    }
    usleep(50_000);
}
$admissionConnection->commit();
$refreshResult = $collectWorker($refreshProcess, $refreshPipes);
$assert($refreshReady, 'refresh worker reached the barrier');
$assert($refreshBlocked > 0, 'refresh waited for the admission event lock');
$assert(($refreshResult['reason'] ?? null) === 'validation_error', 'refresh rejected the committed admission');
$assert(DB::table('admissions')->where('client_scan_id', "refresh-race-{$suffix}")->count() === 1, 'the admission is preserved');
$assert(DB::table('node_event_imports')->where('event_id', $raceEventId)->where('version', 1)->count() === 1, 'the prior import remains active');
echo "\n";

// =============================================================================
// CASE V - Credential rotation blocks an in-flight staff login
// =============================================================================
echo "CASE V: staff login waits for credential rotation\n";
$authPayload = $racePayload;
$authPayload['snapshot_id'] = (string) Str::uuid();
$authPayload['version'] = 1;
$authPayload['event']['id'] += 100000;
$authPayload['event']['slug'] = "staff-login-{$suffix}";
$authPayload['event']['organizer']['id'] += 100000;
$authPayload['event']['organizer']['email'] = "staff-owner-{$suffix}@example.test";
$authPayload['event']['venue']['id'] += 100000;
$authPayload['event']['venue']['code'] = "AUTH{$suffix}";
$authPayload['access_points'] = [];
$authPayload['registrations'] = [];
$authPayload['staff'][0]['id'] += 100000;
$authPayload['staff'][0]['access_point_id'] = null;
$authPayload['staff'][0]['user']['id'] += 100000;
$authPayload['staff'][0]['user']['email'] = "node-staff-{$suffix}@example.test";
$authJson = json_encode($authPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
app(ReceiveSnapshot::class)->execute(app(SnapshotEnvelope::class)->seal('race-node', $nodeSecret, $authJson));
app(ImportReceivedSnapshot::class)->execute($authPayload['event']['id']);
$authCloudUser = $authPayload['staff'][0]['user']['id'];
$staffPassword = app(ManageNodeStaffCredential::class)->provision($authCloudUser);
$localUser = DB::table('node_import_ids')->where('kind', 'user')->where('cloud_id', $authCloudUser)->value('local_id');
$rotatedHash = Hash::make(bin2hex(random_bytes(16)));
$rotationConnection = $rawConnection($testDatabase);
$rotationConnection->beginTransaction();
$rotationConnection->query('select id from runtime_identity where id = 1 for update');
$rotationConnection->prepare('update node_staff_credentials set password_hash = ? where user_id = ?')->execute([$rotatedHash, $localUser]);
[$staffProcess, $staffPipes] = $spawnWorker('node_staff_login', [
    'node_id' => 'race-node', 'email' => $authPayload['staff'][0]['user']['email'], 'password' => $staffPassword,
], $testDatabase, $childScript);
$staffReady = trim((string) fgets($staffPipes[1])) === 'READY';
fwrite($staffPipes[0], 'GO'.PHP_EOL);
fflush($staffPipes[0]);
$staffDeadline = microtime(true) + 20.0;
$staffBlocked = 0;
while (microtime(true) < $staffDeadline) {
    $staffBlocked = $blockedBackends($rotationConnection, $testDatabase);
    if ($staffBlocked > 0) {
        break;
    }
    usleep(50_000);
}
$rotationConnection->commit();
$staffResult = $collectWorker($staffProcess, $staffPipes);
$assert($staffReady, 'staff login worker reached the barrier');
$assert($staffBlocked > 0, 'staff login waited for credential rotation');
$assert(($staffResult['reason'] ?? null) === 'validation_error', 'the rotated credential was rejected');
$assert(DB::table('personal_access_tokens')->where('tokenable_id', $localUser)->count() === 0, 'no token was issued for the old credential');
echo "\n";

// =============================================================================
// CASE W - Receipt issuance waits for a newly staged snapshot
// =============================================================================
echo "CASE W: an import receipt waits for a newer snapshot head\n";
$receiptConnection = $rawConnection($testDatabase);
$receiptConnection->beginTransaction();
$receiptConnection->prepare('update received_event_heads set version = 2 where event_id = ?')->execute([$authPayload['event']['id']]);
[$receiptProcess, $receiptPipes] = $spawnWorker('import_receipt', [
    'node_id' => 'race-node', 'secret' => $nodeSecret, 'event_id' => $authPayload['event']['id'],
], $testDatabase, $childScript);
$receiptReady = trim((string) fgets($receiptPipes[1])) === 'READY';
fwrite($receiptPipes[0], 'GO'.PHP_EOL);
fflush($receiptPipes[0]);
$receiptDeadline = microtime(true) + 20.0;
$receiptBlocked = 0;
while (microtime(true) < $receiptDeadline) {
    $receiptBlocked = $blockedBackends($receiptConnection, $testDatabase);
    if ($receiptBlocked > 0) {
        break;
    }
    usleep(50_000);
}
$receiptConnection->commit();
$receiptResult = $collectWorker($receiptProcess, $receiptPipes);
$assert($receiptReady, 'import receipt worker reached the barrier');
$assert($receiptBlocked > 0, 'receipt issuance waited for the snapshot head');
$assert(($receiptResult['reason'] ?? null) === 'validation_error', 'the stale import could not produce a receipt');
echo "\n";

// =============================================================================
// CASE X - Activation cannot race past a newer staged snapshot
// =============================================================================
echo "CASE X: authority activation waits for a newer snapshot head\n";
DB::table('received_event_heads')->where('event_id', $authPayload['event']['id'])->update(['version' => 1]);
$importForGrant = DB::table('node_event_imports')->where('cloud_event_id', $authPayload['event']['id'])->first();
$authorityKeys = sodium_crypto_sign_keypair();
$authorityPrivate = base64_encode(sodium_crypto_sign_secretkey($authorityKeys));
$authorityPublic = base64_encode(sodium_crypto_sign_publickey($authorityKeys));
config(['ingress.authority_private_key' => $authorityPrivate, 'ingress.authority_public_key' => $authorityPublic]);
$grant = app(AuthorityGrant::class)->sign([
    'protocol' => 1, 'node_id' => 'race-node', 'snapshot_id' => $importForGrant->snapshot_id,
    'event_id' => (int) $importForGrant->cloud_event_id, 'version' => (int) $importForGrant->version, 'sha256' => $importForGrant->sha256,
]);
$authorityConnection = $rawConnection($testDatabase);
$authorityConnection->beginTransaction();
$authorityConnection->prepare('update received_event_heads set version = 2 where event_id = ?')->execute([$authPayload['event']['id']]);
[$authorityProcess, $authorityPipes] = $spawnWorker('activate_authority', [
    'node_id' => 'race-node', 'public_key' => $authorityPublic, 'grant' => $grant,
], $testDatabase, $childScript);
$authorityReady = trim((string) fgets($authorityPipes[1])) === 'READY';
fwrite($authorityPipes[0], 'GO'.PHP_EOL);
fflush($authorityPipes[0]);
$authorityDeadline = microtime(true) + 20.0;
$authorityBlocked = 0;
while (microtime(true) < $authorityDeadline) {
    $authorityBlocked = $blockedBackends($authorityConnection, $testDatabase);
    if ($authorityBlocked > 0) {
        break;
    }
    usleep(50_000);
}
$authorityConnection->commit();
$authorityResult = $collectWorker($authorityProcess, $authorityPipes);
$assert($authorityReady, 'authority worker reached the barrier');
$assert($authorityBlocked > 0, 'authority activation waited for the snapshot head');
$assert(($authorityResult['reason'] ?? null) === 'validation_error', 'activation rejected the stale import');
$assert(DB::table('node_event_authorities')->where('cloud_event_id', $authPayload['event']['id'])->count() === 0, 'no authority was activated');
echo "\n";

// =============================================================================
// CASE Y - Two node scanners admit the same ticket
// =============================================================================
echo "CASE Y: two node scanners race for one ticket\n";
$nodePayload = $racePayload;
$nodePayload['snapshot_id'] = (string) Str::uuid();
$nodePayload['version'] = 1;
$nodePayload['event']['id'] += 600000;
$nodePayload['event']['slug'] = "node-admission-{$suffix}";
$nodePayload['event']['organizer']['id'] += 600000;
$nodePayload['event']['organizer']['email'] = "node-owner-{$suffix}@example.test";
$nodePayload['event']['venue']['id'] += 600000;
$nodePayload['event']['venue']['code'] = "NODE{$suffix}";
$nodePayload['access_points'][0]['id'] += 600000;
$nodeGateB = $nodePayload['access_points'][0];
$nodeGateB['id']++;
$nodeGateB['name'] = 'Node Race Gate B';
$nodeGateB['code'] = 'NODE-B'.$suffix;
$nodePayload['access_points'][] = $nodeGateB;
$nodePayload['staff'][0]['id'] += 600000;
$nodePayload['staff'][0]['access_point_id'] = null;
$nodePayload['staff'][0]['user']['id'] += 600000;
$nodePayload['staff'][0]['user']['email'] = "node-scan-staff-{$suffix}@example.test";
$nodePayload['registrations'][0]['id'] += 600000;
$nodePayload['registrations'][0]['attendee']['id'] += 600000;
$nodePayload['registrations'][0]['attendee']['email'] = "node-scan-attendee-{$suffix}@example.test";
$nodePayload['registrations'][0]['ticket']['id'] += 600000;
$nodePayload['registrations'][0]['ticket']['public_id'] = "node-scan-{$suffix}";
$nodeQrSecret = str_repeat('a', 64);
$nodePayload['registrations'][0]['ticket']['qr_hash'] = hash('sha256', $nodeQrSecret);
$nodeJson = json_encode($nodePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
app(ReceiveSnapshot::class)->execute(app(SnapshotEnvelope::class)->seal('race-node', $nodeSecret, $nodeJson));
$nodeEventId = app(ImportReceivedSnapshot::class)->execute($nodePayload['event']['id']);
$nodeImport = DB::table('node_event_imports')->where('event_id', $nodeEventId)->first();
$nodeGrant = app(AuthorityGrant::class)->sign([
    'protocol' => 1, 'node_id' => 'race-node', 'snapshot_id' => $nodeImport->snapshot_id,
    'event_id' => (int) $nodeImport->cloud_event_id, 'version' => (int) $nodeImport->version, 'sha256' => $nodeImport->sha256,
]);
app(ActivateEventAuthority::class)->execute($nodeGrant);
app(ManageNodeStaffCredential::class)->provision($nodePayload['staff'][0]['user']['id']);
$nodeStaffId = DB::table('node_import_ids')->where('kind', 'user')->where('cloud_id', $nodePayload['staff'][0]['user']['id'])->value('local_id');
$nodeGateId = DB::table('node_import_ids')->where('kind', 'gate')->where('cloud_id', $nodePayload['access_points'][0]['id'])->value('local_id');
$nodeGateBId = DB::table('node_import_ids')->where('kind', 'gate')->where('cloud_id', $nodeGateB['id'])->value('local_id');
$nodeToken = User::findOrFail($nodeStaffId)->createToken('race', ['node:staff'], now()->addHours(1))->accessToken;
$nodeJob = [
    'node_id' => 'race-node', 'public_key' => $authorityPublic, 'staff_user_id' => $nodeStaffId,
    'token_id' => $nodeToken->id, 'event_id' => $nodeEventId, 'access_point_id' => $nodeGateId,
    'qr_payload' => "ingress:v1:node-scan-{$suffix}:{$nodeQrSecret}",
];
$nodeResults = $runConcurrently([
    ['node_scan', $nodeJob + ['client_scan_id' => "node-a-{$suffix}"]],
    ['node_scan', $nodeJob + ['client_scan_id' => "node-b-{$suffix}"]],
], $childScript, $testDatabase);
$assert(count(array_filter($nodeResults, fn ($result) => ($result['status'] ?? null) === 'admitted')) === 1, 'one node scanner admitted the ticket');
$assert(count(array_filter($nodeResults, fn ($result) => ($result['status'] ?? null) === 'already_admitted')) === 1, 'the other node scanner rejected the duplicate');
$nodeTicketId = DB::table('node_import_ids')->where('kind', 'ticket')->where('cloud_id', $nodePayload['registrations'][0]['ticket']['id'])->value('local_id');
$assert(DB::table('admissions')->where('ticket_id', $nodeTicketId)->count() === 1, 'the node recorded one admission');
$movementResults = $runConcurrently([
    ['node_scan', $nodeJob + ['client_scan_id' => "node-exit-{$suffix}", 'direction' => 'exit', 'access_point_id' => $nodeGateId]],
    ['node_scan', $nodeJob + ['client_scan_id' => "node-reentry-{$suffix}", 'direction' => 'entry', 'access_point_id' => $nodeGateBId]],
], $childScript, $testDatabase);
$assert(count(array_filter($movementResults, fn ($result) => ($result['status'] ?? null) === 'exited')) === 1,
    'one gate recorded the exit while the other transition waited');
$assert(count(array_filter($movementResults, fn ($result) => in_array($result['status'] ?? null,
    ['already_admitted', 'reentry_not_allowed'], true))) === 1, 'the other gate rejected the conflicting transition');
$assert(DB::table('admissions')->where('ticket_id', $nodeTicketId)->count() === 2, 'ticket locking preserved the entry-exit sequence');
$assert(DB::table('admissions')->where('ticket_id', $nodeTicketId)->orderByDesc('id')->value('direction') === 'exit',
    're-entry remained disabled by the imported event policy');
echo "\n";

// =============================================================================
// CASE Z - A revoked token cannot replay while its revocation is in flight
// =============================================================================
echo "CASE Z: node admission waits for staff token revocation\n";
$nodeAdmissionsBeforeRevocation = DB::table('admissions')->where('ticket_id', $nodeTicketId)->count();
$nodeRevocation = $rawConnection($testDatabase);
$nodeRevocation->beginTransaction();
$nodeRevocation->query('select id from runtime_identity where id = 1 for update');
$nodeRevocation->prepare('delete from personal_access_tokens where id = ?')->execute([$nodeToken->id]);
[$nodeProcess, $nodePipes] = $spawnWorker('node_scan', $nodeJob + ['client_scan_id' => "node-a-{$suffix}"], $testDatabase, $childScript);
$nodeReady = trim((string) fgets($nodePipes[1])) === 'READY';
fwrite($nodePipes[0], 'GO'.PHP_EOL);
fflush($nodePipes[0]);
$nodeDeadline = microtime(true) + 20.0;
$nodeBlocked = 0;
while (microtime(true) < $nodeDeadline) {
    $nodeBlocked = $blockedBackends($nodeRevocation, $testDatabase);
    if ($nodeBlocked > 0) {
        break;
    }
    usleep(50_000);
}
$nodeRevocation->commit();
$revokedNodeResult = $collectWorker($nodeProcess, $nodePipes);
$assert($nodeReady, 'node scan worker reached the barrier');
$assert($nodeBlocked > 0, 'node scan waited for credential revocation');
$assert(($revokedNodeResult['status'] ?? null) === 'node_authority_unavailable', 'the revoked token could not replay a scan');
$assert(DB::table('admissions')->where('ticket_id', $nodeTicketId)->count() === $nodeAdmissionsBeforeRevocation,
    'revocation created no extra movement');
echo "\n";

// =============================================================================
// CASE AA - Concurrent delivery of a node batch is idempotent
// =============================================================================
echo "CASE AA: concurrent admission batch delivery\n";
$syncEvent = Event::create([
    'organizer_id' => $organizer->id, 'venue_id' => $venue->id, 'title' => 'Sync race', 'slug' => "sync-race-{$suffix}",
    'capacity' => 2, 'registration_opens_at' => now()->subDay(), 'registration_closes_at' => now()->addDay(),
    'starts_at' => now(), 'ends_at' => now()->addDay(), 'status' => Event::STATUS_PUBLISHED,
]);
$syncGate = AccessPoint::create(['event_id' => $syncEvent->id, 'name' => 'Sync Gate', 'code' => 'SYNC', 'is_active' => true]);
$syncStaff = User::create(['name' => 'Sync Staff', 'email' => "sync-staff-{$suffix}@example.test", 'role' => User::ROLE_STAFF, 'password' => Hash::make('password')]);
EventStaff::create(['event_id' => $syncEvent->id, 'user_id' => $syncStaff->id, 'access_point_id' => $syncGate->id]);
$syncStudent = User::create(['name' => 'Sync Student', 'email' => "sync-student-{$suffix}@example.test", 'student_id' => "SYNC-{$suffix}", 'role' => User::ROLE_STUDENT, 'password' => Hash::make('password')]);
$syncTicket = (new RegisterForEvent)->execute($syncStudent, $syncEvent->id)['ticket'];
$syncEvent->update(['registration_closed_at' => now()]);
config(['ingress.mode' => 'cloud']);
$syncSnapshotId = app(PrepareEventSnapshot::class)->execute($syncEvent->id)['id'];
$syncSnapshot = DB::table('event_snapshots')->where('id', $syncSnapshotId)->first();
$syncSecret = base64_encode(random_bytes(32));
DB::table('event_nodes')->insert(['id' => 'sync-race-node', 'key_ciphertext' => Crypt::encryptString($syncSecret), 'created_at' => now(), 'updated_at' => now()]);
DB::table('event_snapshots')->where('id', $syncSnapshotId)->update(['target_node_id' => 'sync-race-node']);
DB::table('cloud_event_authorities')->insert(['event_id' => $syncEvent->id, 'snapshot_id' => $syncSnapshotId, 'node_id' => 'sync-race-node', 'grant' => 'test', 'issued_at' => now()]);
$syncPayload = [
    'schema_version' => 1, 'batch_id' => (string) Str::uuid(), 'node_id' => 'sync-race-node',
    'event_id' => $syncEvent->id, 'snapshot_id' => $syncSnapshotId, 'version' => (int) $syncSnapshot->version,
    'snapshot_sha256' => $syncSnapshot->sha256,
    'admissions' => [['admission_id' => 1, 'ticket_id' => $syncTicket->id, 'gate_id' => $syncGate->id, 'staff_id' => $syncStaff->id,
        'scan_id' => "sync-scan-{$suffix}", 'credential_version' => 1, 'admitted_at' => now()->utc()->startOfSecond()->toISOString()]],
];
$syncEnvelope = app(AdmissionSyncEnvelope::class);
$syncPackage = $syncEnvelope->seal(json_encode($syncPayload, JSON_THROW_ON_ERROR), $syncSecret);
$syncResults = $runConcurrently([
    ['receive_admissions', ['node_id' => 'sync-race-node', 'package' => $syncPackage]],
    ['receive_admissions', ['node_id' => 'sync-race-node', 'package' => $syncPackage]],
], $childScript, $testDatabase);
$assert(($syncResults[0]['ok'] ?? false) && ($syncResults[1]['ok'] ?? false), 'both batch deliveries succeeded');
$assert(! empty($syncResults[0]['receipt']) && $syncResults[0]['receipt'] === ($syncResults[1]['receipt'] ?? null), 'both deliveries returned the same receipt');
$assert(DB::table('admissions')->where('ticket_id', $syncTicket->id)->count() === 1, 'cloud stored exactly one admission');
$assert(DB::table('cloud_synced_admissions')->where('node_id', 'sync-race-node')->count() === 1, 'one node admission mapping exists');
echo "\n";

// =============================================================================
// CASE AB - Rebatched retries of one admission remain idempotent
// =============================================================================
echo "CASE AB: concurrent rebatching of an already synchronized admission\n";
$syncPayload['batch_id'] = (string) Str::uuid();
$syncPackageA = $syncEnvelope->seal(json_encode($syncPayload, JSON_THROW_ON_ERROR), $syncSecret);
$syncPayload['batch_id'] = (string) Str::uuid();
$syncPackageB = $syncEnvelope->seal(json_encode($syncPayload, JSON_THROW_ON_ERROR), $syncSecret);
$rebatched = $runConcurrently([
    ['receive_admissions', ['node_id' => 'sync-race-node', 'package' => $syncPackageA]],
    ['receive_admissions', ['node_id' => 'sync-race-node', 'package' => $syncPackageB]],
], $childScript, $testDatabase);
$assert(($rebatched[0]['ok'] ?? false) && ($rebatched[1]['ok'] ?? false), 'both matching rebatched retries succeeded');
$assert(DB::table('admissions')->where('ticket_id', $syncTicket->id)->count() === 1, 'rebatching did not duplicate the admission');
echo "\n";

// =============================================================================
// CASE AC - Concurrent node batch preparation keeps one immutable batch
// =============================================================================
echo "CASE AC: concurrent node admission batch preparation\n";
$batchJob = ['node_id' => 'race-node', 'secret' => $nodeSecret, 'public_key' => $authorityPublic, 'event_id' => $nodePayload['event']['id']];
$batchResults = $runConcurrently([
    ['prepare_admissions', $batchJob],
    ['prepare_admissions', $batchJob],
], $childScript, $testDatabase);
$assert(($batchResults[0]['ok'] ?? false) && ($batchResults[1]['ok'] ?? false), 'both batch preparations succeeded');
$assert(! empty($batchResults[0]['batch_id']) && $batchResults[0]['batch_id'] === ($batchResults[1]['batch_id'] ?? null), 'both workers reused one batch ID');
$assert(! empty($batchResults[0]['package_hash']) && $batchResults[0]['package_hash'] === ($batchResults[1]['package_hash'] ?? null), 'both workers returned the same encrypted package');
$assert(DB::table('node_admission_batches')->where('event_id', $nodeEventId)->count() === 1, 'one pending batch exists');
echo "\n";

// =============================================================================
// CASE AD - A pairing code is consumed at most once
// =============================================================================
echo "CASE AD: concurrent redemption of one scanner pairing code\n";
config(['ingress.mode' => 'node', 'ingress.node_id' => 'race-node']);
DB::table('received_event_heads')->where('event_id', $authPayload['event']['id'])->update(['version' => 1]);
app(ManageNodeStaffCredential::class)->provision($authCloudUser);
$pairCode = app(NodeStaffPairing::class)->issue($authCloudUser)['token'];
$pairResults = $runConcurrently([
    ['redeem_pairing', ['node_id' => 'race-node', 'token' => $pairCode]],
    ['redeem_pairing', ['node_id' => 'race-node', 'token' => $pairCode]],
], $childScript, $testDatabase);
$assert(count(array_filter($pairResults, fn ($result) => ($result['ok'] ?? false) === true)) === 1, 'one scanner redeemed the code');
$assert(count(array_filter($pairResults, fn ($result) => ($result['reason'] ?? null) === 'validation_error')) === 1, 'the other redemption was rejected');
$assert(DB::table('personal_access_tokens')->where('tokenable_id', $localUser)->count() === 1, 'one node session was issued');
echo "\n";

// =============================================================================
// CASE AE - Pairing waits for credential rotation
// =============================================================================
echo "CASE AE: scanner pairing waits for credential rotation\n";
$rotationPair = app(NodeStaffPairing::class)->issue($authCloudUser)['token'];
$pairRotationConnection = $rawConnection($testDatabase);
$pairRotationConnection->beginTransaction();
$pairRotationConnection->query('select id from runtime_identity where id = 1 for update');
$pairRotationConnection->prepare('delete from node_staff_pairings where user_id = ?')->execute([$localUser]);
[$pairProcess, $pairPipes] = $spawnWorker('redeem_pairing', ['node_id' => 'race-node', 'token' => $rotationPair], $testDatabase, $childScript);
$pairReady = trim((string) fgets($pairPipes[1])) === 'READY';
fwrite($pairPipes[0], 'GO'.PHP_EOL);
fflush($pairPipes[0]);
$pairDeadline = microtime(true) + 20.0;
$pairBlocked = 0;
while (microtime(true) < $pairDeadline) {
    $pairBlocked = $blockedBackends($pairRotationConnection, $testDatabase);
    if ($pairBlocked > 0) {
        break;
    }
    usleep(50_000);
}
$pairRotationConnection->commit();
$rotatedPairResult = $collectWorker($pairProcess, $pairPipes);
$assert($pairReady, 'pairing worker reached the barrier');
$assert($pairBlocked > 0, 'pairing waited for credential rotation');
$assert(($rotatedPairResult['reason'] ?? null) === 'validation_error', 'the invalidated code was rejected');
$assert(DB::table('personal_access_tokens')->where('tokenable_id', $localUser)->count() === 1, 'no additional session was issued');
echo "\n";

// =============================================================================
// CASE AF - Guest and student registrations share the capacity lock
// =============================================================================
echo "CASE AF: guest and student compete for one remaining seat\n";
$guestRaceEvent = Event::create([
    'organizer_id' => $organizer->id, 'venue_id' => $venue->id, 'title' => 'Guest capacity race',
    'slug' => "guest-capacity-{$suffix}", 'capacity' => 1, 'registration_opens_at' => now()->subHour(),
    'registration_closes_at' => now()->addDay(), 'starts_at' => now(), 'ends_at' => now()->addDay(),
    'status' => Event::STATUS_PUBLISHED,
]);
$guestRaceStudent = User::create(['name' => 'Guest Race Student', 'email' => "guest-race-student-{$suffix}@example.test",
    'student_id' => "GS-{$suffix}", 'role' => User::ROLE_STUDENT, 'password' => Hash::make('password')]);
$guestRaceResults = $runConcurrently([
    ['guest_registration', ['event_id' => $guestRaceEvent->id, 'email' => "guest-race-{$suffix}@example.test"]],
    ['seat', ['event_id' => $guestRaceEvent->id, 'user_id' => $guestRaceStudent->id]],
], $childScript, $testDatabase);
$assert(count(array_filter($guestRaceResults, fn ($result) => ($result['ok'] ?? false) === true)) === 1, 'exactly one registration succeeded');
$assert(Registration::where('event_id', $guestRaceEvent->id)->where('status', 'confirmed')->count() === 1, 'the event has exactly one confirmed attendee');
$assert(Ticket::whereHas('registration', fn ($query) => $query->where('event_id', $guestRaceEvent->id))->count() === 1, 'exactly one ticket was issued');
echo "\n";

// =============================================================================
// CASE AG - Walk-ins compete for the final node seat
// =============================================================================
echo "CASE AG: concurrent node walk-ins compete for the final seat\n";
$walkInToken = User::findOrFail($nodeStaffId)->createToken('walk-in-race', ['node:staff'], now()->addHours(1))->accessToken;
$walkInJob = [
    'node_id' => 'race-node', 'public_key' => $authorityPublic, 'staff_user_id' => $nodeStaffId,
    'token_id' => $walkInToken->id, 'event_id' => $nodeEventId,
];
DB::table('events')->where('id', $nodeEventId)->update(['capacity' => 2]);
$walkInRace = $runConcurrently([
    ['node_walk_in', $walkInJob + ['request_id' => (string) Str::uuid(), 'email' => "walkin-a-{$suffix}@example.test"]],
    ['node_walk_in', $walkInJob + ['request_id' => (string) Str::uuid(), 'email' => "walkin-b-{$suffix}@example.test"]],
], $childScript, $testDatabase);
$assert(count(array_filter($walkInRace, fn ($result) => ($result['ok'] ?? false) === true)) === 1, 'one walk-in received the final seat');
$assert(count(array_filter($walkInRace, fn ($result) => ($result['reason'] ?? null) === 'validation_error')) === 1, 'the other walk-in was rejected');
$assert(Registration::where('event_id', $nodeEventId)->where('status', 'confirmed')->count() === 2, 'the node did not exceed capacity');
$assert(DB::table('node_walk_in_issuances')->where('event_id', $nodeEventId)->count() === 1, 'one walk-in issuance exists');
echo "\n";

// =============================================================================
// CASE AH - Concurrent retries preserve the same ticket and credential
// =============================================================================
echo "CASE AH: concurrent retries of one node walk-in request\n";
DB::table('events')->where('id', $nodeEventId)->update(['capacity' => 3]);
$replayJob = $walkInJob + ['request_id' => (string) Str::uuid(), 'email' => "walkin-retry-{$suffix}@example.test"];
$walkInRetries = $runConcurrently([
    ['node_walk_in', $replayJob], ['node_walk_in', $replayJob],
], $childScript, $testDatabase);
$assert(($walkInRetries[0]['ok'] ?? false) && ($walkInRetries[1]['ok'] ?? false), 'both retries succeeded');
$assert(! empty($walkInRetries[0]['ticket_id']) && $walkInRetries[0]['ticket_id'] === ($walkInRetries[1]['ticket_id'] ?? null), 'both retries returned the same ticket');
$assert(! empty($walkInRetries[0]['qr_hash']) && $walkInRetries[0]['qr_hash'] === ($walkInRetries[1]['qr_hash'] ?? null), 'both retries returned the same credential');
$assert(DB::table('node_walk_in_issuances')->where('event_id', $nodeEventId)->count() === 2, 'one issuance was added for both retries');
echo "\n";

// =============================================================================
// CASE AI - Concurrent cloud delivery of a walk-in is idempotent
// =============================================================================
echo "CASE AI: concurrent delivery of one walk-in to the cloud\n";
$walkInSync = [
    'schema_version' => 1, 'node_id' => 'sync-race-node', 'event_id' => $syncEvent->id,
    'snapshot_id' => $syncSnapshotId, 'version' => (int) $syncSnapshot->version, 'snapshot_sha256' => $syncSnapshot->sha256,
    'issuance_id' => (string) Str::uuid(), 'staff_id' => $syncStaff->id, 'name' => 'Sync Walk-in',
    'email' => "sync-walkin-{$suffix}@example.test", 'kind' => 'guest', 'student_id' => null,
    'public_id' => 'ING-'.strtoupper(Str::random(10)), 'qr_hash' => hash('sha256', random_bytes(32)),
    'issued_at' => now()->utc()->toISOString(),
];
$walkInPackage = $syncEnvelope->seal(json_encode($walkInSync, JSON_THROW_ON_ERROR), $syncSecret);
$walkInDeliveries = $runConcurrently([
    ['receive_walk_in', ['node_id' => 'sync-race-node', 'package' => $walkInPackage]],
    ['receive_walk_in', ['node_id' => 'sync-race-node', 'package' => $walkInPackage]],
], $childScript, $testDatabase);
$assert(($walkInDeliveries[0]['ok'] ?? false) && ($walkInDeliveries[1]['ok'] ?? false), 'both deliveries succeeded');
$assert(! empty($walkInDeliveries[0]['receipt']) && $walkInDeliveries[0]['receipt'] === ($walkInDeliveries[1]['receipt'] ?? null), 'both deliveries returned the same receipt');
$assert(($walkInDeliveries[0]['ticket_id'] ?? null) === ($walkInDeliveries[1]['ticket_id'] ?? null), 'both deliveries returned the same ticket');
$assert(DB::table('cloud_walk_in_receipts')->where('node_id', 'sync-race-node')->where('issuance_id', $walkInSync['issuance_id'])->count() === 1, 'one cloud receipt exists');
$assert(Registration::where('event_id', $syncEvent->id)->where('guest_email', $walkInSync['email'])->count() === 1, 'one cloud walk-in registration exists');
echo "\n";

// =============================================================================
// CASE AJ - Concurrent delivery of a recovery audit record is idempotent
// =============================================================================
echo "CASE AJ: concurrent cloud recovery audit delivery\n";
$recoveryData = [
    'schema_version' => 2, 'node_id' => 'sync-race-node', 'event_id' => $syncEvent->id,
    'snapshot_id' => $syncSnapshotId, 'version' => (int) $syncSnapshot->version, 'snapshot_sha256' => $syncSnapshot->sha256,
    'recovery_id' => 1, 'issuance_id' => $walkInSync['issuance_id'], 'staff_id' => $syncStaff->id,
    'recovered_at' => now()->utc()->toISOString(), 'identity_verified' => true, 'reason' => 'lost',
];
$recoveryPackage = $syncEnvelope->seal(json_encode($recoveryData, JSON_THROW_ON_ERROR), $syncSecret);
$recoveryDeliveries = $runConcurrently([
    ['receive_walk_in_recovery', ['node_id' => 'sync-race-node', 'package' => $recoveryPackage]],
    ['receive_walk_in_recovery', ['node_id' => 'sync-race-node', 'package' => $recoveryPackage]],
], $childScript, $testDatabase);
$assert(($recoveryDeliveries[0]['ok'] ?? false) && ($recoveryDeliveries[1]['ok'] ?? false), 'both recovery deliveries succeeded');
$assert(! empty($recoveryDeliveries[0]['receipt']) && $recoveryDeliveries[0]['receipt'] === ($recoveryDeliveries[1]['receipt'] ?? null), 'both deliveries returned the same recovery receipt');
$assert(DB::table('cloud_walk_in_recoveries')->where('node_id', 'sync-race-node')->where('node_recovery_id', 1)->count() === 1, 'one cloud recovery audit record exists');
$assert(DB::table('cloud_walk_in_recoveries')->where('node_id', 'sync-race-node')->where('node_recovery_id', 1)
    ->where('identity_verified', true)->where('reason', 'lost')->exists(), 'recovery attestation and reason synchronized');
echo "\n";

// =============================================================================
// CASE AK - Node recovery waits for issuance sync and prepares once
// =============================================================================
echo "CASE AK: concurrent recovery package preparation\n";
config(['ingress.mode' => 'node', 'ingress.node_id' => 'race-node', 'ingress.node_sync_key' => $nodeSecret,
    'ingress.authority_public_key' => $authorityPublic]);
$recoveringStaff = User::findOrFail($nodeStaffId)->withAccessToken($walkInToken);
app(RecoverNodeWalkIn::class)->execute($recoveringStaff, $nodeEventId, $replayJob['email'], 'lost', true);
$assert(DB::table('node_walk_in_recoveries')->where('recovered_by_user_id', $nodeStaffId)
    ->where('identity_verified', true)->where('reason', 'lost')->exists(), 'node recovery records staff attestation and reason');
$assert(app(SyncWalkInRecovery::class)->prepare($nodePayload['event']['id']) === null, 'recovery waits for its issuance mapping');
DB::table('node_walk_in_issuances')->where('client_request_id', $replayJob['request_id'])->update(['synced_at' => now()]);
$recoveryJob = ['node_id' => 'race-node', 'secret' => $nodeSecret, 'public_key' => $authorityPublic, 'event_id' => $nodePayload['event']['id']];
$preparedRecoveries = $runConcurrently([
    ['prepare_walk_in_recovery', $recoveryJob], ['prepare_walk_in_recovery', $recoveryJob],
], $childScript, $testDatabase);
$assert(($preparedRecoveries[0]['ok'] ?? false) && ($preparedRecoveries[1]['ok'] ?? false), 'both preparations succeeded');
$assert(! empty($preparedRecoveries[0]['recovery_id']) && $preparedRecoveries[0]['recovery_id'] === ($preparedRecoveries[1]['recovery_id'] ?? null), 'both preparations selected the same recovery');
$assert(! empty($preparedRecoveries[0]['package_hash']) && $preparedRecoveries[0]['package_hash'] === ($preparedRecoveries[1]['package_hash'] ?? null), 'both preparations returned the same immutable package');
$assert(DB::table('node_walk_in_recoveries')->whereNotNull('sync_package_ciphertext')->count() === 1, 'one pending recovery package exists');
echo "\n";

// =============================================================================
// CASE AL - Gate deactivation cannot race with scanner assignment
// =============================================================================
echo "CASE AL: gate deactivation races with scanner assignment\n";
$gateRaceEvent = Event::create([
    'organizer_id' => $organizer->id,
    'venue_id' => $venue->id,
    'title' => 'Gate Assignment Race',
    'slug' => "gate-assignment-race-{$suffix}",
    'capacity' => 20,
    'registration_opens_at' => now()->subHour(),
    'registration_closes_at' => now()->addDay(),
    'starts_at' => now()->addDays(2),
    'ends_at' => now()->addDays(2)->addHours(3),
    'status' => Event::STATUS_DRAFT,
]);
$gateRaceGate = AccessPoint::create([
    'event_id' => $gateRaceEvent->id,
    'name' => 'Race Gate',
    'code' => 'RACE-GATE',
    'is_active' => true,
]);
$gateRaceStaff = User::create([
    'name' => 'Gate Race Scanner',
    'email' => "gate-race-scanner-{$suffix}@example.test",
    'role' => User::ROLE_STUDENT,
    'password' => Hash::make('password'),
]);
$gateRaceResults = $runConcurrently([
    ['deactivate_gate', [
        'actor_id' => $organizer->id,
        'event_id' => $gateRaceEvent->id,
        'access_point_id' => $gateRaceGate->id,
    ]],
    ['assign_gate_staff', [
        'actor_id' => $organizer->id,
        'event_id' => $gateRaceEvent->id,
        'access_point_id' => $gateRaceGate->id,
        'staff_id' => $gateRaceStaff->id,
    ]],
], $childScript, $testDatabase);
$gateRaceWinners = count(array_filter($gateRaceResults, fn (array $result): bool => ($result['ok'] ?? false) === true));
$gateRaceInvariantHolds = $gateRaceGate->fresh()->is_active
    || ! EventStaff::query()->where('event_id', $gateRaceEvent->id)
        ->where('access_point_id', $gateRaceGate->id)->exists();
$assert($gateRaceWinners === 1, 'exactly one conflicting operation succeeds');
$assert($gateRaceInvariantHolds, 'a scanner is never assigned to a deactivated gate');
echo "\n";

// =============================================================================
// Summary
// =============================================================================
if ($failures === []) {
    echo "POSTGRESQL CONCURRENCY VERIFICATION PASSED\n";
    exit(0);
}

echo "POSTGRESQL CONCURRENCY VERIFICATION FAILED\n";
foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}
exit(1);
