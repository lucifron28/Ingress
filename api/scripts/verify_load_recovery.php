<?php

/**
 * PostgreSQL load and synchronization recovery verification for Ingress.
 *
 * Creates isolated cloud and node databases, imports a 1,000-attendee event,
 * admits attendees through four concurrent node scanner processes, and checks
 * replay after the cloud commits a batch but its response is lost.
 *
 * Usage (from the api/ directory):
 *   php scripts/verify_load_recovery.php
 *
 * Set INGRESS_LOAD_ATTENDEES, INGRESS_LOAD_WORKERS, and INGRESS_LOAD_DB to
 * adjust the workload and isolated database prefix.
 */

use App\Actions\ActivateEventAuthority;
use App\Actions\AuthorityGrant;
use App\Actions\ExportSnapshot;
use App\Actions\ImportReceivedSnapshot;
use App\Actions\ManageNodeStaffCredential;
use App\Actions\PrepareEventSnapshot;
use App\Actions\ReceiveSnapshot;
use App\Models\AccessPoint;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    $assertions++;
    echo ($condition ? '  [PASS] ' : '  [FAIL] ').$label.PHP_EOL;
    if (! $condition) {
        $failures[] = $label;
    }
};

$runArtisan = static function (string $command): void {
    if (Artisan::call($command, ['--force' => true, '--no-interaction' => true]) !== 0) {
        throw new RuntimeException(Artisan::output() ?: "Failed to run {$command}.");
    }
};

$attendeeCount = filter_var(getenv('INGRESS_LOAD_ATTENDEES') ?: '1000', FILTER_VALIDATE_INT);
$workerCount = filter_var(getenv('INGRESS_LOAD_WORKERS') ?: '4', FILTER_VALIDATE_INT);
$databasePrefix = getenv('INGRESS_LOAD_DB') ?: 'ingress_load_'.gmdate('Ymd_His');
$cloudDatabase = $databasePrefix;
$nodeDatabase = $databasePrefix.'_node';
$nodeId = 'load-recovery-node';
$secret = base64_encode(random_bytes(32));
$keypair = sodium_crypto_sign_keypair();
$authorityPublic = base64_encode(sodium_crypto_sign_publickey($keypair));
$authorityPrivate = base64_encode(sodium_crypto_sign_secretkey($keypair));
$workerScript = __DIR__.'/pgsql_load_recovery_worker.php';
$temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ingress-load-'.bin2hex(random_bytes(6));

if (! is_int($attendeeCount) || $attendeeCount < 500 || $attendeeCount > 5000) {
    fwrite(STDERR, "INGRESS_LOAD_ATTENDEES must be between 500 and 5000.\n");
    exit(1);
}
if (! is_int($workerCount) || $workerCount < 2 || $workerCount > 16) {
    fwrite(STDERR, "INGRESS_LOAD_WORKERS must be between 2 and 16.\n");
    exit(1);
}
if ($workerCount > $attendeeCount || ! preg_match('/^[a-z][a-z0-9_]{0,55}$/', $cloudDatabase)
    || ! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $nodeDatabase)) {
    fwrite(STDERR, "Invalid workload or database name.\n");
    exit(1);
}

$baseConnection = config('database.connections.pgsql');
if (! is_array($baseConnection) || ($baseConnection['driver'] ?? null) !== 'pgsql') {
    fwrite(STDERR, "The PostgreSQL connection is not configured.\n");
    exit(1);
}

$admin = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=postgres', $baseConnection['host'], $baseConnection['port']),
    $baseConnection['username'],
    $baseConnection['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$exists = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
foreach ([$cloudDatabase, $nodeDatabase] as $database) {
    $exists->execute([$database]);
    if ($exists->fetchColumn()) {
        fwrite(STDERR, "Refusing to reset existing database '{$database}'. Choose another INGRESS_LOAD_DB.\n");
        exit(1);
    }
    $admin->exec(sprintf('CREATE DATABASE "%s"', $database));
}

echo "Load setup: {$attendeeCount} attendees, {$workerCount} scanner workers, PostgreSQL {$baseConnection['host']}:{$baseConnection['port']}.\n";
echo "Isolated databases: {$cloudDatabase} (cloud), {$nodeDatabase} (node).\n";

config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.url' => null,
    'database.connections.pgsql.database' => $cloudDatabase,
    'database.connections.load_cloud' => array_merge($baseConnection, ['url' => null, 'database' => $cloudDatabase]),
    'database.connections.load_node' => array_merge($baseConnection, ['url' => null, 'database' => $nodeDatabase]),
]);
DB::purge('pgsql');
DB::purge('load_cloud');
DB::purge('load_node');
$runArtisan('migrate:fresh');

config(['database.connections.pgsql.database' => $nodeDatabase]);
DB::purge('pgsql');
$runArtisan('migrate:fresh');
config(['database.connections.pgsql.database' => $cloudDatabase]);
DB::purge('pgsql');
DB::setDefaultConnection('pgsql');

$serverVersion = DB::selectOne('SHOW server_version')->server_version;
$suffix = Str::lower(Str::random(8));
$now = now();

config(['ingress.mode' => 'cloud']);
$owner = User::create([
    'name' => 'Load Harness Organizer',
    'email' => "load-owner-{$suffix}@example.test",
    'role' => User::ROLE_ORGANIZER,
    'password' => Hash::make('load-harness-password'),
]);
$venue = Venue::create([
    'name' => 'Load Harness Venue',
    'code' => 'LOAD-'.strtoupper($suffix),
    'location' => 'Automated PostgreSQL load harness',
    'default_capacity' => $attendeeCount,
]);
$event = Event::create([
    'organizer_id' => $owner->id,
    'venue_id' => $venue->id,
    'title' => 'Load and Recovery Verification',
    'slug' => "load-recovery-{$suffix}",
    'capacity' => $attendeeCount,
    'registration_opens_at' => $now->copy()->subDays(3),
    'registration_closes_at' => $now->copy()->subDay(),
    'starts_at' => $now->copy()->subHour(),
    'ends_at' => $now->copy()->addHours(4),
    'status' => Event::STATUS_PUBLISHED,
    're_entry_allowed' => false,
]);
DB::table('events')->where('id', $event->id)->update(['registration_closed_at' => $now]);

$gates = [];
$staffUsers = [];
for ($gateNumber = 0; $gateNumber < $workerCount; $gateNumber++) {
    $gate = AccessPoint::create([
        'event_id' => $event->id,
        'name' => 'Load Gate '.($gateNumber + 1),
        'code' => 'LG-'.($gateNumber + 1),
        'is_active' => true,
    ]);
    $staff = User::create([
        'name' => 'Load Gate Staff '.($gateNumber + 1),
        'email' => "load-staff-{$gateNumber}-{$suffix}@example.test",
        'role' => User::ROLE_STAFF,
        'password' => Hash::make('load-harness-password'),
    ]);
    EventStaff::create(['event_id' => $event->id, 'user_id' => $staff->id, 'access_point_id' => $gate->id]);
    $gates[] = $gate;
    $staffUsers[] = $staff;
}

$registrationRows = [];
for ($attendee = 1; $attendee <= $attendeeCount; $attendee++) {
    $registrationRows[] = [
        'event_id' => $event->id,
        'user_id' => null,
        'attendee_type' => 'guest',
        'guest_name' => sprintf('Load Attendee %04d', $attendee),
        'guest_email' => sprintf('load-%s-%04d@example.test', $suffix, $attendee),
        'guest_student_id' => null,
        'walk_in_kind' => null,
        'status' => 'confirmed',
        'cancelled_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}
foreach (array_chunk($registrationRows, 250) as $chunk) {
    DB::table('registrations')->insert($chunk);
}

$registrations = DB::table('registrations')->where('event_id', $event->id)->orderBy('id')->get(['id']);
$ticketRows = [];
$qrPayloads = [];
foreach ($registrations as $index => $registration) {
    $attendee = $index + 1;
    $publicId = sprintf('load-%s-%04d', $suffix, $attendee);
    $qrSecret = bin2hex(random_bytes(32));
    $ticketRows[] = [
        'registration_id' => $registration->id,
        'public_id' => $publicId,
        'qr_hash' => hash('sha256', $qrSecret),
        'encrypted_secret' => Crypt::encryptString($qrSecret),
        'status' => 'active',
        'credential_version' => 1,
        'issued_at' => $now,
        'revoked_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $qrPayloads[] = "ingress:v1:{$publicId}:{$qrSecret}";
}
foreach (array_chunk($ticketRows, 250) as $chunk) {
    DB::table('tickets')->insert($chunk);
}

$snapshot = app(PrepareEventSnapshot::class)->execute($event->id);
DB::table('event_nodes')->insert([
    'id' => $nodeId,
    'key_ciphertext' => Crypt::encryptString($secret),
    'created_at' => $now,
    'updated_at' => $now,
]);
$sealedSnapshot = app(ExportSnapshot::class)->execute($event->id, $nodeId);

DB::setDefaultConnection('load_node');
config([
    'ingress.mode' => 'node',
    'ingress.node_id' => $nodeId,
    'ingress.node_sync_key' => $secret,
    'ingress.authority_public_key' => $authorityPublic,
]);
app(ReceiveSnapshot::class)->execute($sealedSnapshot);
$localEventId = app(ImportReceivedSnapshot::class)->execute($event->id);
$import = DB::table('node_event_imports')->where('cloud_event_id', $event->id)->first();
$grantMetadata = [
    'protocol' => 1,
    'node_id' => $nodeId,
    'snapshot_id' => $import->snapshot_id,
    'event_id' => (int) $event->id,
    'version' => (int) $import->version,
    'sha256' => $import->sha256,
];
config([
    'ingress.authority_private_key' => $authorityPrivate,
    'ingress.authority_public_key' => $authorityPublic,
]);
$grant = app(AuthorityGrant::class)->sign($grantMetadata);
app(ActivateEventAuthority::class)->execute($grant);
DB::table('node_event_authorities')->where('cloud_event_id', $event->id)
    ->update(['grant' => $grant]);

DB::setDefaultConnection('pgsql');
config([
    'ingress.mode' => 'cloud',
    'ingress.node_id' => null,
    'ingress.node_sync_key' => null,
    'ingress.authority_private_key' => $authorityPrivate,
    'ingress.authority_public_key' => $authorityPublic,
]);
DB::table('cloud_event_authorities')->insert([
    'event_id' => $event->id,
    'snapshot_id' => $snapshot['id'],
    'node_id' => $nodeId,
    'grant' => $grant,
    'issued_at' => now(),
]);

DB::setDefaultConnection('load_node');
config([
    'ingress.mode' => 'node',
    'ingress.node_id' => $nodeId,
    'ingress.node_sync_key' => $secret,
    'ingress.authority_public_key' => $authorityPublic,
]);
$scannerJobs = [];
for ($worker = 0; $worker < $workerCount; $worker++) {
    $cloudStaffId = $staffUsers[$worker]->id;
    $localStaffId = (int) DB::table('node_import_ids')
        ->where('kind', 'user')->where('cloud_id', $cloudStaffId)->value('local_id');
    $localGateId = (int) DB::table('node_import_ids')
        ->where('kind', 'gate')->where('cloud_id', $gates[$worker]->id)->value('local_id');
    app(ManageNodeStaffCredential::class)->provision($cloudStaffId);
    $staff = User::findOrFail($localStaffId);
    $token = $staff->createToken('load-harness-scanner', ['node:staff'], now()->addHours(2))->accessToken;
    $scans = [];
    for ($attendeeIndex = $worker; $attendeeIndex < $attendeeCount; $attendeeIndex += $workerCount) {
        $scans[] = [
            'qr_payload' => $qrPayloads[$attendeeIndex],
            'client_scan_id' => (string) Str::uuid(),
        ];
    }
    $scannerJobs[] = [
        'node_database' => $nodeDatabase,
        'node_id' => $nodeId,
        'authority_public_key' => $authorityPublic,
        'event_id' => $localEventId,
        'staff_user_id' => $localStaffId,
        'token_id' => $token->id,
        'access_point_id' => $localGateId,
        'scans' => $scans,
    ];
}

if (! mkdir($temporaryDirectory, 0700, true) && ! is_dir($temporaryDirectory)) {
    throw new RuntimeException('Could not create the temporary load fixture directory.');
}
$scanFiles = [];
foreach ($scannerJobs as $worker => $job) {
    $path = $temporaryDirectory.DIRECTORY_SEPARATOR."scanner-{$worker}.json";
    file_put_contents($path, json_encode($job, JSON_THROW_ON_ERROR));
    $scanFiles[] = $path;
}

$processes = [];
$workerPipes = [];
foreach ($scanFiles as $worker => $path) {
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open([PHP_BINARY, $workerScript, 'scan', $path], $descriptors, $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start a scanner worker.');
    }
    $processes[$worker] = $process;
    $workerPipes[$worker] = $pipes;
}

$allReady = true;
foreach ($workerPipes as $worker => $pipes) {
    $allReady = $allReady && trim((string) fgets($pipes[1])) === 'READY';
}
if (! $allReady) {
    foreach ($processes as $process) {
        proc_terminate($process);
    }
    throw new RuntimeException('A scanner worker failed before the start barrier.');
}

$scanStartedAt = microtime(true);
foreach ($workerPipes as $pipes) {
    fwrite($pipes[0], "GO\n");
    fflush($pipes[0]);
}
$scanResults = [];
foreach ($workerPipes as $worker => $pipes) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($processes[$worker]);
    $result = null;
    foreach (explode(PHP_EOL, (string) $stdout) as $line) {
        if (str_starts_with($line, 'RESULT:')) {
            $result = json_decode(substr($line, 7), true);
        }
    }
    if (! is_array($result)) {
        $result = ['ok' => false, 'detail' => trim((string) $stdout.' '.(string) $stderr)];
    }
    $result['process_exit_code'] = $exitCode;
    $scanResults[] = $result;
}
$scanElapsed = microtime(true) - $scanStartedAt;

$scanSuccesses = array_sum(array_map(fn (array $result) => (int) ($result['admitted'] ?? 0), $scanResults));
$scanFailures = array_sum(array_map(fn (array $result) => (int) ($result['failed'] ?? 0), $scanResults));
$latencies = [];
foreach ($scanResults as $result) {
    $latencies = array_merge($latencies, $result['latencies_ms'] ?? []);
}
sort($latencies, SORT_NUMERIC);
$percentile = static fn (float $fraction): float => $latencies === [] ? 0.0 : (float) $latencies[(int) floor((count($latencies) - 1) * $fraction)];
$nodeAdmissionCount = (int) DB::table('admissions')
    ->join('tickets', 'tickets.id', '=', 'admissions.ticket_id')
    ->join('registrations', 'registrations.id', '=', 'tickets.registration_id')
    ->where('registrations.event_id', $localEventId)->count();
$distinctTicketCount = (int) DB::table('admissions')
    ->join('tickets', 'tickets.id', '=', 'admissions.ticket_id')
    ->join('registrations', 'registrations.id', '=', 'tickets.registration_id')
    ->where('registrations.event_id', $localEventId)->distinct('admissions.ticket_id')->count('admissions.ticket_id');
$gateCounts = DB::table('admissions')->whereIn('access_point_id', array_column($scannerJobs, 'access_point_id'))
    ->select('access_point_id', DB::raw('count(*) as total'))->groupBy('access_point_id')
    ->orderBy('access_point_id')->get()->mapWithKeys(fn (object $gate) => [(int) $gate->access_point_id => (int) $gate->total])->all();
$expectedEachGate = intdiv($attendeeCount, $workerCount);
$remainder = $attendeeCount % $workerCount;
$expectedGateCounts = [];
foreach ($scannerJobs as $worker => $job) {
    $expectedGateCounts[$job['access_point_id']] = $expectedEachGate + ($worker < $remainder ? 1 : 0);
}
ksort($expectedGateCounts);
$gateDistributionMatches = $gateCounts === $expectedGateCounts;

$assert(count($scanResults) === $workerCount && count(array_filter($scanResults, fn (array $result) => ! ($result['ok'] ?? false))) === 0,
    "all {$workerCount} scanner processes completed successfully");
$assert($scanSuccesses === $attendeeCount && $scanFailures === 0, "all {$attendeeCount} unique attendees were admitted exactly once");
$assert($nodeAdmissionCount === $attendeeCount && $distinctTicketCount === $attendeeCount, 'PostgreSQL contains one admission for every unique ticket');
$assert($gateDistributionMatches, 'admissions reached every concurrent gate');

echo sprintf(
    "Scan result: attendees=%d gates=%d workers=%d seconds=%.2f scans_per_second=%.1f p50_ms=%.2f p95_ms=%.2f max_ms=%.2f\n",
    $attendeeCount, $workerCount, $workerCount, $scanElapsed, $scanElapsed > 0 ? $attendeeCount / $scanElapsed : 0,
    $percentile(0.50), $percentile(0.95), $latencies === [] ? 0.0 : (float) max($latencies)
);
echo 'Gate distribution: '.json_encode($gateCounts, JSON_THROW_ON_ERROR)."; expected per gate about {$expectedEachGate} (remainder {$remainder}).\n";

$syncBase = [
    'node_database' => $nodeDatabase,
    'cloud_database' => $cloudDatabase,
    'node_id' => $nodeId,
    'node_sync_key' => $secret,
    'authority_public_key' => $authorityPublic,
    'cloud_event_id' => $event->id,
    'local_event_id' => $localEventId,
];
$runSyncWorker = static function (array $payload, string $mode) use ($workerScript, $temporaryDirectory): array {
    $path = $temporaryDirectory.DIRECTORY_SEPARATOR."sync-{$mode}.json";
    $payload['mode'] = $mode;
    file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
    $process = proc_open([PHP_BINARY, $workerScript, 'sync', $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start a synchronization worker.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    foreach (explode(PHP_EOL, (string) $stdout) as $line) {
        if (str_starts_with($line, 'RESULT:')) {
            $result = json_decode(substr($line, 7), true);
            if (is_array($result)) {
                $result['process_exit_code'] = $exitCode;

                return $result;
            }
        }
    }

    return ['ok' => false, 'process_exit_code' => $exitCode, 'detail' => trim((string) $stdout.' '.(string) $stderr)];
};

$offlineStartedAt = microtime(true);
$offline = $runSyncWorker($syncBase, 'commit_then_drop');
$offlineElapsed = microtime(true) - $offlineStartedAt;
$assert(($offline['exit_code'] ?? null) === 1 && ($offline['requests'] ?? null) === 1,
    'the disconnected node process stopped after its cloud response was lost');
$assert(($offline['cloud_admissions'] ?? null) === 100 && ($offline['node_synced_admissions'] ?? null) === 0
    && ($offline['pending_batches'] ?? null) === 1, 'cloud commit did not acknowledge or discard the node pending batch');

$recoveryStartedAt = microtime(true);
$recovery = $runSyncWorker($syncBase, 'recover');
$recoveryElapsed = microtime(true) - $recoveryStartedAt;
$assert(($recovery['exit_code'] ?? null) === 0 && ($recovery['requests'] ?? null) === (int) ceil($attendeeCount / 100),
    'a fresh process replayed the pending batch and completed synchronization');
$assert(($recovery['initial_batch_id'] ?? null) === ($offline['initial_batch_id'] ?? null)
    && ($recovery['initial_package_sha256'] ?? null) === ($offline['initial_package_sha256'] ?? null),
    'the first batch ID and package survived restart unchanged');
$assert(($recovery['cloud_admissions'] ?? null) === $attendeeCount
    && ($recovery['node_synced_admissions'] ?? null) === $attendeeCount
    && ($recovery['pending_batches'] ?? null) === 0
    && ($recovery['cloud_batches'] ?? null) === (int) ceil($attendeeCount / 100),
    'cloud and node converged with no duplicate attendees or pending batches');
echo sprintf(
    "Recovery result: first_run_seconds=%.2f restart_replay_seconds=%.2f replayed_batch=%s total_batches=%d cloud_admissions=%d node_synced=%d pending=%d\n",
    $offlineElapsed, $recoveryElapsed, $offline['initial_batch_id'] ?? 'missing',
    (int) ceil($attendeeCount / 100), $recovery['cloud_admissions'] ?? 0,
    $recovery['node_synced_admissions'] ?? 0, $recovery['pending_batches'] ?? 0
);

foreach (glob($temporaryDirectory.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
    unlink($path);
}
rmdir($temporaryDirectory);

if (getenv('INGRESS_LOAD_KEEP_DATABASES') === '1') {
    echo "Retained verification databases {$cloudDatabase} and {$nodeDatabase}.\n";
} else {
    DB::purge('pgsql');
    DB::purge('load_cloud');
    DB::purge('load_node');
    $admin->exec(sprintf('DROP DATABASE "%s" WITH (FORCE)', $nodeDatabase));
    $admin->exec(sprintf('DROP DATABASE "%s" WITH (FORCE)', $cloudDatabase));
    echo "Removed isolated verification databases.\n";
}

echo "Assertions: {$assertions}; failures: ".count($failures)."; PostgreSQL: {$serverVersion}; PHP: ".PHP_VERSION.".\n";
if ($failures !== []) {
    fwrite(STDERR, 'POSTGRESQL LOAD AND RECOVERY VERIFICATION FAILED: '.implode('; ', $failures).PHP_EOL);
    exit(1);
}
echo "POSTGRESQL LOAD AND RECOVERY VERIFICATION PASSED.\n";
