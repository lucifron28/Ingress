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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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

// --- 1. Provision the dedicated concurrency database --------------------------
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

// --- 2. Fixtures --------------------------------------------------------------
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
$runConcurrently = function (array $jobs, string $childScript, string $testDatabase) use ($baseConnection): array {
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

    // Wait for every child to reach the barrier.
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

    // Release both children simultaneously.
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
