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
 * Both cases release two child PHP processes through a two-way barrier so the
 * database transactions genuinely overlap.
 *
 * Usage (from the api/ directory):
 *   php scripts/verify_pgsql_concurrency.php
 *
 * The script provisions a dedicated "ingress_concurrency" database so it never
 * touches demonstration data.
 */

use App\Actions\RegisterForEvent;
use App\Models\AccessPoint;
use App\Models\Admission;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

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

$exists = $admin->query("SELECT 1 FROM pg_database WHERE datname = '{$testDatabase}'")->fetchColumn();

if (! $exists) {
    $admin->exec(sprintf('CREATE DATABASE "%s"', $testDatabase));
    echo "  created.\n";
} else {
    echo "  already exists.\n";
}

config(['database.connections.pgsql.database' => $testDatabase]);
DB::purge('pgsql');

Artisan::call('migrate:fresh', ['--force' => true]);
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
